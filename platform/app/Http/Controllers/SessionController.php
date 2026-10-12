<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Models\User;
use App\Support\Credentials;
use App\Support\LoginThrottle;
use App\Support\MultiFactor;
use App\Support\SchoolMemberships;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Sign-in and choice of school (W1.1, FR-IAM-02). A person signs in with their email, or with
 * their username in a school; with MFA, a second step asks for a code. The chosen school is
 * kept in the server session; it is only ever one of the user's active memberships.
 */
final class SessionController
{
    private static ?string $decoy = null;

    public function __construct(private Credentials $credentials) {}

    public function login(Request $request, LoginThrottle $throttle)
    {
        $data = $request->validate(['login' => 'required|string|max:200', 'password' => 'required|string|max:200',
            'school' => 'nullable|string|max:40']);
        $school = config('platform.dedicated_school') ?: ($data['school'] ?? null);
        $key = $data['login'].'|'.$school;
        if ($wait = $throttle->wait($key, $request->ip())) return $this->locked($wait);

        $id = DB::selectOne('SELECT platform.find_login(?, ?) AS id', [$data['login'], $school])->id;
        $user = $id ? $this->person($id) : null;
        $hash = $user ? $this->credentials->passwordHash($user->id) : null;
        // The same work and the same answer whether the account exists, has no password yet, or the password is wrong.
        $valid = Hash::check($data['password'], $hash ?: (self::$decoy ??= Hash::make(Str::random(40))));
        if (!$hash || !$valid) {
            $throttle->failed($key, $request->ip());
            return Refusal::response('invalid_credentials', 401);
        }
        $throttle->succeeded($key);
        if ($user->mfa_enabled_at) {
            $request->session()->regenerate();
            $request->session()->put('auth.pending', ['user' => $user->id, 'at' => now()->getTimestamp(), 'school' => $school]);
            return ['mfa_required' => true];
        }
        return $this->open($request, $user, $school);
    }

    /** Second step: a TOTP code, or one of the recovery codes. */
    public function mfa(Request $request, LoginThrottle $throttle, MultiFactor $mfa)
    {
        $data = $request->validate(['code' => 'required_without:recovery_code|nullable|string|max:20',
            'recovery_code' => 'nullable|string|max:40']);
        $pending = $request->session()->get('auth.pending');
        if (!$pending || now()->getTimestamp() - $pending['at'] > config('platform.mfa_pending_seconds')) {
            $request->session()->forget('auth.pending');
            return Refusal::response('mfa_expired', 401);
        }
        $user = $this->person($pending['user']) ?? throw (new ModelNotFoundException())->setModel(User::class);
        $key = 'mfa|'.$user->id;
        if ($wait = $throttle->wait($key, $request->ip())) return $this->locked($wait);
        $ok = isset($data['code']) ? $mfa->verify($user, $data['code']) : $mfa->useRecoveryCode($user, $data['recovery_code']);
        if (!$ok) {
            $throttle->failed($key, $request->ip());
            return Refusal::response('invalid_code', 401);
        }
        $throttle->succeeded($key);
        $request->session()->forget('auth.pending');
        return $this->open($request, $user, $pending['school']);
    }

    public function logout(Request $request)
    {
        $this->end($request);
        return response()->noContent();
    }

    public function schools(Request $request)
    {
        return ['schools' => $this->schoolsOf((int) $request->user()->getAuthIdentifier()), 'selected' => $request->session()->get('school.tenant')];
    }

    public function select(Request $request)
    {
        $data = $request->validate(['tenant_id' => 'required|uuid']);
        if (!$this->schoolsOf((int) $request->user()->getAuthIdentifier())->contains('tenant_id', $data['tenant_id'])) {
            return Refusal::response('school_forbidden', 403);
        }
        // A new session identifier for a new school: nothing carries over between schools.
        $request->session()->regenerate();
        $request->session()->put('school.tenant', $data['tenant_id']);
        $request->session()->forget(['school.idle_minutes', 'locale', 'support.grant']);
        return response()->noContent();
    }

    private function open(Request $request, User $user, ?string $school)
    {
        $schools = $this->schoolsOf($user->id);
        if ($schools->isEmpty()) {
            // FR-PLT-01 AC2: no session without an open school (suspended, revoked, none).
            $this->end($request);
            return Refusal::response('no_school', 403);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(['auth.started_at' => now()->getTimestamp(), 'auth.seen_at' => now()->getTimestamp()]);
        // One school (always the case for a dedicated installation) opens directly, as does the school signed in to.
        $selected = $schools->count() === 1 ? $schools->first()->tenant_id : $schools->firstWhere('slug', $school)?->tenant_id;
        if ($selected) $request->session()->put('school.tenant', $selected);
        return ['schools' => $schools, 'selected' => $selected, 'password_change_required' => $user->must_change_password];
    }

    private function locked(int $seconds): JsonResponse
    {
        return Refusal::response('too_many_attempts', 429)->header('Retry-After', (string) $seconds);
    }

    /** The person before any school: read in their own context (SECURITY-REVIEW.md, F2). */
    private function person(int $id): ?User
    {
        return Auth::guard('web')->getProvider()->retrieveById($id);
    }

    /** The schools this user may open; a dedicated installation offers only its own. */
    private function schoolsOf(int $user): Collection
    {
        return app(SchoolMemberships::class)->forUser($user);
    }

    private function end(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
