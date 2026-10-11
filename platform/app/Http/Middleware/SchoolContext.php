<?php

namespace App\Http\Middleware;

use App\Support\CurrentSchool;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs a school request in one transaction on the restricted connection (ARC-02, BR-06).
 * The school comes from the server session, chosen at sign-in among the user's active
 * memberships; never from the URL, a header or the request body.
 */
final class SchoolContext
{
    public function __construct(private CurrentSchool $school) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (!$user) return response()->json(['message' => 'Connectez-vous.', 'code' => 'unauthenticated'], 401);
        $tenant = $request->session()->get('school.tenant');
        if (!is_string($tenant)) {
            return response()->json(['message' => 'Choisissez un établissement.', 'code' => 'school_not_selected'], 409);
        }
        $requestId = CurrentSchool::requestId($request->headers->get('X-Request-Id'));
        $closed = null;
        $support = $request->session()->get('support.grant');
        $response = DB::transaction(function () use ($request, $next, $user, $tenant, $requestId, $support, &$closed) {
            $this->school->enter($tenant, $user->getAuthIdentifier(), $requestId, $request->ip(), $support);
            $state = DB::selectOne('SELECT platform.tenant_mode(?::uuid) AS mode, school.allowed(?::uuid) AS allowed', [$tenant, $tenant]);
            if ($state->mode === 'suspended') { $closed = 'tenant_suspended'; return null; }
            if (!$state->allowed) { $closed = $support ? 'support_ended' : 'membership_inactive'; return null; }
            $this->school->setMode($state->mode);
            if ($support) {
                // FR-PLT-04: every consultation in the school's journal, then nothing more is written.
                DB::select('SELECT school.log_support_access(?)', [$request->method().' /'.$request->path()]);
                DB::statement('SET LOCAL transaction_read_only = on');
            }
            // FR-IAM-02: the school's inactivity delay, and MFA before any access for whoever
            // holds a sensitive permission here (AC1); enrolment itself is outside the school.
            $policy = DB::selectOne("SELECT school.mfa_required() AS mfa, (school.setting_value('session.idle_minutes'))::int AS idle");
            $request->session()->put('school.idle_minutes', $policy->idle);
            if ($policy->mfa && !$user->mfa_enabled_at) { $closed = 'mfa_setup_required'; return null; }
            DB::statement('SET LOCAL lock_timeout = '.DB::getPdo()->quote(config('platform.lock_timeout')));
            DB::statement('SET LOCAL statement_timeout = '.DB::getPdo()->quote(config('platform.statement_timeout')));
            $response = $next($request);
            // Laravel renders controller exceptions before returning here: re-throw so a
            // failed write rolls the whole transaction back.
            if ($response->exception ?? null) throw $response->exception;
            return $response;
        }, 3);
        if ($closed === 'support_ended') {
            // The consent expired or was revoked: the support session ends, the operator stays signed in.
            $request->session()->forget(['support.grant', 'school.tenant', 'school.idle_minutes']);
            return response()->json(['message' => 'L’accès support a pris fin : le consentement a expiré ou a été retiré.', 'code' => $closed], 403);
        }
        if ($closed === 'mfa_setup_required') {
            return response()->json(['message' => 'Activez la double authentification pour accéder à cet établissement.', 'code' => $closed], 403);
        }
        if ($closed) {
            // FR-PLT-01 AC2 / FR-IAM-01 AC1: a suspended school or membership ends the session.
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = $closed === 'tenant_suspended' ? 'Cet établissement est suspendu.' : 'Votre accès à cet établissement n’est plus actif.';
            return response()->json(['message' => $message, 'code' => $closed], 403);
        }
        // Through the header bag: file downloads are streamed responses.
        $response->headers->set('X-Request-Id', $requestId);
        return $response;
    }
}
