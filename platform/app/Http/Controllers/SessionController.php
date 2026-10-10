<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\LoginThrottle;
use App\Support\MultiFactor;
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

    public function login(Request $request, LoginThrottle $throttle)
    {
        $data = $request->validate(['login' => 'required|string|max:200', 'password' => 'required|string|max:200',
            'school' => 'nullable|string|max:40']);
        $school = config('platform.dedicated_school') ?: ($data['school'] ?? null);
        $key = $data['login'].'|'.$school;
        if ($wait = $throttle->wait($key, $request->ip())) return $this->locked($wait);

        $id = DB::selectOne('SELECT platform.find_login(?, ?) AS id', [$data['login'], $school])->id;
        $user = $id ? User::find($id) : null;
        // The same work and the same answer whether the account exists, has no password yet, or the password is wrong.
        $valid = Hash::check($data['password'], $user?->password ?: (self::$decoy ??= Hash::make(Str::random(40))));
        if (!$user?->password || !$valid) {
            $throttle->failed($key, $request->ip());
            return response()->json(['message' => 'Identifiants incorrects.', 'code' => 'invalid_credentials'], 401);
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
            return response()->json(['message' => 'Reconnectez-vous.', 'code' => 'mfa_expired'], 401);
        }
        $user = User::findOrFail($pending['user']);
        $key = 'mfa|'.$user->id;
        if ($wait = $throttle->wait($key, $request->ip())) return $this->locked($wait);
        $ok = isset($data['code']) ? $mfa->verify($user, $data['code']) : $mfa->useRecoveryCode($user, $data['recovery_code']);
        if (!$ok) {
            $throttle->failed($key, $request->ip());
            return response()->json(['message' => 'Code incorrect.', 'code' => 'invalid_code'], 401);
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
            return response()->json(['message' => 'Cet établissement ne vous est pas accessible.', 'code' => 'school_forbidden'], 403);
        }
        // A new session identifier for a new school: nothing carries over between schools.
        $request->session()->regenerate();
        $request->session()->put('school.tenant', $data['tenant_id']);
        $request->session()->forget('school.idle_minutes');
        return response()->noContent();
    }

    private function open(Request $request, User $user, ?string $school)
    {
        $schools = $this->schoolsOf($user->id);
        if ($schools->isEmpty()) {
            // FR-PLT-01 AC2: no session without an open school (suspended, revoked, none).
            $this->end($request);
            return response()->json(['message' => 'Aucun établissement accessible avec ce compte.', 'code' => 'no_school'], 403);
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
        return response()->json(['message' => 'Trop de tentatives. Réessayez dans quelques minutes.', 'code' => 'too_many_attempts'], 429)
            ->header('Retry-After', (string) $seconds);
    }

    /** The schools this user may open; a dedicated installation offers only its own. */
    private function schoolsOf(int $user): Collection
    {
        $schools = collect(DB::transaction(function () use ($user) {
            DB::select("SELECT set_config('app.user_id', ?, true)", [(string) $user]);
            return DB::select('SELECT tenant_id, slug, name, default_locale, mode FROM school.my_schools()');
        }));
        $pinned = config('platform.dedicated_school');
        return $pinned ? $schools->where('slug', $pinned)->values() : $schools;
    }

    private function end(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
