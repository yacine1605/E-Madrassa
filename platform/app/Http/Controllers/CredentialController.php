<?php

namespace App\Http\Controllers;

use App\Exceptions\Refusal;
use App\Rules\StrongPassword;
use App\Support\Credentials;
use App\Support\DatabaseContext;
use App\Support\MultiFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** The signed-in person's own credentials: password and MFA (FR-IAM-02). Outside any school. */
final class CredentialController
{
    public function __construct(private Credentials $credentials) {}

    public function password(Request $request)
    {
        $user = $request->user();
        // The person's usernames in their schools; their own memberships are visible to them.
        $username = DB::transaction(function () use ($user) {
            app(DatabaseContext::class)->enter(null, $user->id);
            return DB::selectOne("SELECT string_agg(username, ' ') AS u FROM school.membership WHERE user_id = ?", [$user->id])->u;
        });
        $data = $request->validate([
            'current_password' => 'required|string|max:200',
            'password' => ['required', 'string', 'confirmed', 'different:current_password', new StrongPassword([$user->name, $user->email, $username])],
        ]);
        if (!Hash::check($data['current_password'], (string) $this->credentials->passwordHash($user->id))) {
            throw ValidationException::withMessages(['current_password' => __('errors.current_password_wrong')]);
        }
        // Hashed with Argon2id (NFR-SEC-02).
        $this->credentials->setPassword($user->id, Hash::make($data['password']));
        $user->forceFill(['must_change_password' => false, 'password_changed_at' => now()])->syncOriginal();
        $request->session()->regenerate();
        return response()->noContent();
    }

    /** First step: a new secret, kept in the session until a code confirms it. */
    public function startMfa(Request $request, MultiFactor $mfa)
    {
        $user = $request->user();
        if ($user->mfa_enabled_at) return Refusal::response('mfa_already_enabled', 409);
        $secret = $mfa->newSecret();
        $request->session()->put('mfa.pending_secret', Crypt::encryptString($secret));
        return ['secret' => $secret, 'uri' => $mfa->uri($secret, $user->email ?? $user->name)];
    }

    /** Second step: a code from the app turns MFA on; the recovery codes are shown this once. */
    public function confirmMfa(Request $request, MultiFactor $mfa)
    {
        $data = $request->validate(['code' => 'required|string|max:20']);
        $user = $request->user();
        $pending = $request->session()->get('mfa.pending_secret');
        if ($user->mfa_enabled_at || !$pending) return Refusal::response('mfa_not_started', 409);
        $secret = Crypt::decryptString($pending);
        $step = $mfa->check($secret, $data['code'], null);
        if ($step === null) throw ValidationException::withMessages(['code' => __('errors.invalid_code')]);
        $codes = $mfa->enable($user, $secret, $step);
        $request->session()->forget('mfa.pending_secret');
        return ['recovery_codes' => $codes];
    }
}
