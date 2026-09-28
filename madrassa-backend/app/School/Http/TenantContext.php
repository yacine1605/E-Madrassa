<?php
namespace App\School\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

final class TenantContext
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user(), 401);
        $tenant = $request->route('tenant');
        abort_unless(is_string($tenant) && \Illuminate\Support\Str::isUuid($tenant), 404);
        // A URL, browser state, or extra membership cannot switch this installation's school.
        abort_unless($tenant === \App\School\Services\DeploymentSchool::id(), 403);
        try {
            // Local settings reset on commit/rollback, including pooled connections.
            return DB::transaction(function () use ($request, $next, $tenant) {
                DB::select("select set_config('app.user_id', ?, true)", [(string) $request->user()->id]);
                $member = DB::table('school.memberships')->where('tenant_id', $tenant)
                    ->where('user_id', $request->user()->id)->where('active', true)->first();
                abort_unless($member, 403);
                $request->attributes->set('school.permissions', json_decode($member->permissions, true));
                $request->attributes->set('school.tenant', $tenant);
                DB::select("select set_config('app.tenant_id', ?, true)", [$tenant]);
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement("SET LOCAL statement_timeout = '10s'");
                return $next($request);
            }, 3);
        } catch (QueryException $e) {
            // Never return SQL, bindings or personal data in API errors.
            if (in_array($e->getCode(), ['23503','23505','23514','23P01','55P03','40001','40P01'])) {
                return response()->json(['message' => 'Opération incompatible avec l’état actuel des données. Rechargez puis vérifiez les contraintes.', 'code' => 'domain_conflict'], 409);
            }
            throw $e;
        }
    }
}
