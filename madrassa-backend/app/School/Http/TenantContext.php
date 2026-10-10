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
                self::traceRequest($request);
                $member = DB::table('school.memberships')->where('tenant_id', $tenant)
                    ->where('user_id', $request->user()->id)->where('active', true)->first();
                abort_unless($member, 403);
                $modules = app(\App\School\Modules\ModuleRegistry::class);
                // Enforce feature switches at the API boundary as well as in navigation.
                if ($request->is('school-api/*')) {
                    $resource = $request->route('resource');
                    $enabled = is_string($resource) ? $modules->resourceEnabled($resource)
                        : $modules->apiActionEnabled($request->route()->getActionMethod());
                    if (!$enabled) abort(response()->json(['message' => 'Ce module n’est pas activé pour cet établissement.', 'code' => 'module_disabled'], 403));
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
                    'cohort_full' => 'Cette classe est complète.',
                    'overpayment' => 'Ce montant dépasse le reste à payer.',
                    'segregation_of_duties' => 'Séparation des tâches : la personne qui a saisi cette opération ne peut pas la valider.',
                    'duplicate_payment_reference' => 'Cette référence de paiement est déjà utilisée.',
                    'payment_reversed' => 'Ce paiement a été annulé par une contre-opération.',
                    'reversal_after_refund' => 'Ce paiement a déjà été remboursé ; il ne peut plus être annulé.',
                    'payroll_not_validated' => 'La rémunération doit être validée avant tout versement.',
                    'code_immutable' => 'Le code ou le matricule est définitif et ne peut pas être modifié.',
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
            // The runtime role lacks the privilege: a refusal, not a server fault.
            if ((string) $e->getCode() === '42501') {
                return response()->json(['message' => 'Cette opération n’est pas autorisée sur ces données.', 'code' => 'forbidden'], 403);
            }
            throw $e;
        }
    }

    /** Ties audit entries written in this transaction to the request that caused them. */
    public static function traceRequest(Request $request): void
    {
        $id = $request->headers->get('X-Request-Id');
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $id)) $id = (string) \Illuminate\Support\Str::uuid();
        $request->attributes->set('request_id', $id);
        DB::select("select set_config('app.request_id', ?, true), set_config('app.ip', ?, true)", [$id, (string) $request->ip()]);
    }
}
