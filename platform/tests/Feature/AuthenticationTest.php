<?php

namespace Tests\Feature;

use App\Mail\AccountLink;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/** Sign-in, MFA, lockout, password policy, session limits and links (FR-IAM-02, FR-IAM-01). */
final class AuthenticationTest extends TestCase
{
    private function tokenFrom(AccountLink $mail): string
    {
        return substr($mail->url, strpos($mail->url, '#token=') + 7);
    }

    public function test_after_five_failures_the_next_attempt_waits_whatever_the_password(): void
    {
        $school = $this->school();
        $account = $this->account('user', mfa: false);
        $this->member($school, $account);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/session', ['login' => $account['email'], 'password' => 'wrong-'.$i])
                ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        }
        // FR-IAM-02 AC2: the sixth attempt is refused, even with the right password; the message reveals nothing.
        $this->postJson('/api/session', ['login' => $account['email'], 'password' => $account['password']])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertHeader('Retry-After');
        $this->travel(61)->seconds();
        $this->postJson('/api/session', ['login' => $account['email'], 'password' => 'wrong-again'])->assertUnauthorized();
        // Progressive: one more failure, a longer wait.
        $this->travel(61)->seconds();
        $this->postJson('/api/session', ['login' => $account['email'], 'password' => $account['password']])->assertStatus(429);
        $this->travel(2)->minutes();
        $this->signIn($account)->assertOk();
    }

    public function test_the_schools_application_reads_no_credential_yet_sign_in_and_password_change_work(): void
    {
        $school = $this->school();
        $account = $this->account('user');
        $this->member($school, $account);
        // SECURITY-REVIEW.md, F2 and F3: school_runtime sees no hash, secret or code, and changes none.
        foreach (['SELECT password FROM public.users WHERE id = ?', 'SELECT mfa_secret FROM public.users WHERE id = ?',
            'SELECT code_hash FROM public.mfa_recovery_code WHERE user_id = ?', "UPDATE public.users SET password = 'x' WHERE id = ?"] as $sql) {
            try {
                DB::statement($sql, [$account['id']]);
                $this->fail("Allowed to school_runtime: $sql");
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->getCode());
            }
        }
        $person = User::findOrFail($account['id'])->fresh();
        $this->assertSame(User::COLUMNS, array_keys($person->getAttributes()));
        $this->assertTrue($person->has_password);

        $this->signIn($account)->assertOk();
        $fresh = 'Un autre figuier 2027';
        $this->putJson('/api/account/password', ['current_password' => $account['password'], 'password' => $fresh, 'password_confirmation' => $fresh])
            ->assertNoContent();
        $this->deleteJson('/api/session');
        $this->postJson('/api/session', ['login' => $account['email'], 'password' => $account['password']])->assertUnauthorized();
        $this->signIn($account, $fresh)->assertOk();
    }

    public function test_an_unknown_account_gets_the_same_answer_as_a_wrong_password(): void
    {
        $this->postJson('/api/session', ['login' => 'nobody@example.test', 'password' => 'whatever-123'])
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
        $this->postJson('/api/session', ['login' => 'nobody', 'password' => 'whatever-123', 'school' => 'nowhere'])
            ->assertUnauthorized()->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_privileged_access_waits_for_mfa_then_codes_are_single_use(): void
    {
        $school = $this->school();
        $account = $this->account('accountant', mfa: false);
        $this->member($school, $account, 'active', ['accountant']);
        $this->signIn($account)->assertOk();
        // FR-IAM-02 AC1: an accountant without MFA reaches nothing in the school until it is on.
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'mfa_setup_required');
        $secret = $this->postJson('/api/account/mfa')->assertOk()->json('secret');
        $this->postJson('/api/account/mfa/confirm', ['code' => '000000'])->assertUnprocessable();
        $code = (new Google2FA())->getCurrentOtp($secret);
        $recovery = $this->postJson('/api/account/mfa/confirm', ['code' => $code])->assertOk()->assertJsonCount(8, 'recovery_codes')->json('recovery_codes');
        $this->getJson('/api/me')->assertOk();

        // The code just used is not accepted again; a recovery code works once.
        $this->deleteJson('/api/session');
        $this->postJson('/api/session', ['login' => $account['email'], 'password' => $account['password']])->assertOk()->assertJsonPath('mfa_required', true);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/session/mfa', ['code' => $code])->assertUnauthorized()->assertJsonPath('code', 'invalid_code');
        $this->postJson('/api/session/mfa', ['recovery_code' => strtolower($recovery[0])])->assertOk();
        $this->deleteJson('/api/session');
        $this->postJson('/api/session', ['login' => $account['email'], 'password' => $account['password']])->assertOk();
        $this->postJson('/api/session/mfa', ['recovery_code' => $recovery[0]])->assertUnauthorized();
    }

    public function test_a_pupil_signs_in_with_a_username_and_changes_the_provisional_password(): void
    {
        [$school, $admin] = [$this->school(), $this->account('admin')];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin);
        $provisional = $this->postJson('/api/accounts', ['name' => 'A. Kaci', 'username' => 'Amine.K', 'method' => 'password'])
            ->assertCreated()->assertJsonPath('data.status', 'active')->json('provisional_password');

        $this->deleteJson('/api/session');
        $this->postJson('/api/session', ['login' => 'amine.k', 'password' => $provisional])->assertUnauthorized();
        $this->postJson('/api/session', ['login' => 'AMINE.K', 'password' => $provisional, 'school' => $school->slug])
            ->assertOk()->assertJsonPath('selected', $school->id)->assertJsonPath('password_change_required', true);
        $this->getJson('/api/school')->assertForbidden()->assertJsonPath('code', 'password_change_required');
        // FR-IAM-02: at least 10 characters, not common, not the person's own name.
        foreach (['court', 'Motdepasse2024!', 'azertyuiop', 'amine.k-2026', '1234567890'] as $weak) {
            $this->putJson('/api/account/password', ['current_password' => $provisional, 'password' => $weak, 'password_confirmation' => $weak])
                ->assertUnprocessable()->assertJsonValidationErrors('password');
        }
        $this->putJson('/api/account/password', ['current_password' => $provisional, 'password' => 'Jasmin sous la pluie 7',
            'password_confirmation' => 'Jasmin sous la pluie 7'])->assertNoContent();
        $this->getJson('/api/school')->assertOk();
    }

    public function test_a_session_ends_after_the_schools_inactivity_delay_and_after_twelve_hours(): void
    {
        $school = $this->school();
        [$admin, $user] = [$this->account('admin'), $this->account('user', mfa: false)];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->member($school, $user);
        $this->signIn($user);
        $this->getJson('/api/school')->assertOk();
        $this->travel(31)->minutes();
        $this->getJson('/api/school')->assertUnauthorized()->assertJsonPath('code', 'session_expired');

        // The school allows 60 minutes (15 to 120).
        $this->signIn($admin);
        $this->putJson('/api/settings/session.idle_minutes', ['value' => 200])->assertStatus(409)->assertJsonPath('code', 'setting_invalid');
        $this->putJson('/api/settings/session.idle_minutes', ['value' => 60])->assertOk()->assertJsonPath('data.value', 60);
        $this->signIn($user);
        $this->getJson('/api/school')->assertOk();
        $this->travel(45)->minutes();
        $this->getJson('/api/school')->assertOk();
        // Active all along, the session still ends after 12 hours.
        for ($elapsed = 45; $elapsed <= 12 * 60; $elapsed += 45) {
            $this->travel(45)->minutes();
            $response = $this->getJson('/api/school');
            if ($elapsed + 45 <= 12 * 60) $response->assertOk();
            else $response->assertUnauthorized()->assertJsonPath('code', 'session_expired');
        }
    }

    public function test_an_activation_link_opens_the_account_once(): void
    {
        Mail::fake();
        [$school, $admin] = [$this->school(), $this->account('admin')];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $this->signIn($admin);
        $email = 'nouvelle-'.uniqid().'@example.test';
        $this->postJson('/api/accounts', ['name' => 'Salima Benali', 'email' => $email, 'method' => 'link'])
            ->assertCreated()->assertJsonPath('data.status', 'invited')->assertJsonPath('delivery', 'sent')->assertJsonMissingPath('activation_url');
        $token = null;
        Mail::assertSent(AccountLink::class, function (AccountLink $mail) use ($email, &$token) {
            $token = $this->tokenFrom($mail);
            return $mail->hasTo($email) && $mail->purpose === 'activation';
        });

        $this->deleteJson('/api/session');
        $this->postJson('/api/activation/check', ['token' => $token])->assertOk()->assertJsonPath('data.name', 'Salima Benali')
            ->assertJsonPath('data.needs_password', true);
        $this->postJson('/api/activation', ['token' => $token, 'password' => 'salima2026!!', 'password_confirmation' => 'salima2026!!'])
            ->assertUnprocessable();
        $this->postJson('/api/activation', ['token' => $token, 'password' => 'Le figuier du jardin 9', 'password_confirmation' => 'Le figuier du jardin 9'])
            ->assertNoContent();
        $this->postJson('/api/activation', ['token' => $token, 'password' => 'Un autre essai 2026', 'password_confirmation' => 'Un autre essai 2026'])
            ->assertStatus(409)->assertJsonPath('code', 'token_invalid');
        $this->postJson('/api/activation/check', ['token' => $token])->assertNotFound();
        $this->postJson('/api/session', ['login' => $email, 'password' => 'Le figuier du jardin 9'])->assertOk()->assertJsonPath('selected', $school->id);
    }

    public function test_a_recovery_link_works_once_and_reveals_nothing(): void
    {
        Mail::fake();
        $school = $this->school();
        $account = $this->account('user', mfa: false);
        $this->member($school, $account);
        // FR-IAM-02 AC3: the same answer for a known and an unknown address.
        $answer = $this->postJson('/api/recovery/request', ['email' => strtoupper($account['email'])])->assertStatus(202)->json('message');
        $this->postJson('/api/recovery/request', ['email' => 'nobody@example.test'])->assertStatus(202)->assertJsonPath('message', $answer);
        $token = null;
        Mail::assertQueued(AccountLink::class, 1);
        Mail::assertQueued(AccountLink::class, function (AccountLink $mail) use (&$token) { $token = $this->tokenFrom($mail); return $mail->purpose === 'recovery'; });

        $this->postJson('/api/recovery', ['token' => $token, 'password' => 'Les dunes de Taghit 4', 'password_confirmation' => 'Les dunes de Taghit 4'])
            ->assertNoContent();
        $this->postJson('/api/recovery', ['token' => $token, 'password' => 'Encore une fois 2026', 'password_confirmation' => 'Encore une fois 2026'])
            ->assertStatus(409)->assertJsonPath('code', 'token_invalid');
        $this->postJson('/api/session', ['login' => $account['email'], 'password' => $account['password']])->assertUnauthorized();
        $this->signIn($account, 'Les dunes de Taghit 4')->assertOk();
    }

    public function test_when_sending_fails_the_administrator_may_hand_over_a_new_persons_link_only(): void
    {
        [$school, $admin] = [$this->school(), $this->account('admin')];
        $this->member($school, $admin, 'active', ['tenant_admin']);
        $elsewhere = $this->account('known');
        $this->member($this->school(), $elsewhere);
        $this->signIn($admin);
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP unavailable'));
        $this->postJson('/api/accounts', ['name' => 'Nouveau', 'email' => 'nouveau-'.uniqid().'@example.test', 'method' => 'link'])
            ->assertCreated()->assertJsonPath('delivery', 'failed')->assertJsonPath('activation_url', fn ($url) => str_contains($url, '/activation#token='));
        // A person who already has a password: their account is theirs, no link is handed over.
        $this->postJson('/api/accounts', ['name' => 'Connu', 'email' => $elsewhere['email'], 'method' => 'link'])
            ->assertCreated()->assertJsonPath('delivery', 'failed')->assertJsonMissingPath('activation_url');
    }
}
