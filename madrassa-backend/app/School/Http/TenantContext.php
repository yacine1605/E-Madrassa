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
                $modules = app(\App\School\Modules\ModuleRegistry::class);
                // Enforce feature switches at the API boundary as well as in navigation.
                if ($request->is('school-api/*')) {
                    $resource = $request->route('resource');
                    if (is_string($resource)) abort_unless($modules->resourceEnabled($resource), 404);
                    else abort_unless($modules->apiActionEnabled($request->route()->getActionMethod()), 404);
                }
                $request->attributes->set('school.permissions', $modules->effectivePermissions(json_decode($member->permissions, true)));
                $request->attributes->set('school.tenant', $tenant);
                DB::select("select set_config('app.tenant_id', ?, true)", [$tenant]);
                DB::statement("SET LOCAL lock_timeout = '3s'");
                DB::statement("SET LOCAL statement_timeout = '10s'");
                $response = $next($request);
                // Laravel's routing pipeline renders controller exceptions before
                // returning here. Re-throw so failed writes roll back and domain
                // conflicts reach the handler below instead of becoming HTTP 500.
                if ($response->exception ?? null) throw $response->exception;
                return $response;
            }, 3);
        } catch (QueryException $e) {
            // Never return SQL, bindings or personal data in API errors.
            if ((string) $e->getCode() === '23514') {
                $messages = [
                    'invalid_room' => 'Choisissez une salle du même campus que le groupe, avec une capacité au moins égale à celle du groupe.',
                    'invalid_teacher' => 'Choisissez un enseignant actif.',
                    'schedule_conflict' => 'Ce créneau est déjà occupé pour la salle, le groupe, l’enseignant ou un élève du groupe.',
                ];
                foreach ($messages as $code => $message) {
                    if (preg_match('/ERROR:\\s+'.preg_quote($code, '/').'\\b/', $e->errorInfo[2] ?? '')) {
                        return response()->json(['message' => $message, 'code' => $code], 409);
                    }
                }
            }
            if (in_array($e->getCode(), ['23503','23505','23514','23P01','55P03','40001','40P01'])) {
                return response()->json(['message' => 'Opération incompatible avec l’état actuel des données. Rechargez puis vérifiez les contraintes.', 'code' => 'domain_conflict'], 409);
            }
            throw $e;
        }
    }
}
