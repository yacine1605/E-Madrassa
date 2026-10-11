<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP (RFC 6238) and single-use recovery codes (FR-IAM-02). The secret is stored encrypted
 * with the application key; a code is never accepted twice; recovery codes are kept hashed.
 */
final class MultiFactor
{
    private const RECOVERY_CODES = 8;
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private Google2FA $totp) {}

    public function newSecret(): string
    {
        return $this->totp->generateSecretKey(32);
    }

    public function uri(string $secret, string $account): string
    {
        return $this->totp->getQRCodeUrl((string) config('platform.mfa_issuer'), $account, $secret);
    }

    /** The time step of a valid code newer than the last one used, or null. */
    public function check(string $secret, string $code, ?int $lastStep): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return null;
        // With no previous step the library answers true instead of the step: start from step 0.
        $step = $this->totp->verifyKeyNewer($secret, $code, $lastStep ?? 0, 1);
        return is_int($step) ? $step : null;
    }

    /** Checks the user's code and records its step, so that it cannot be replayed. */
    public function verify(User $user, string $code): bool
    {
        $step = $this->check(Crypt::decryptString($user->mfa_secret), $code, $user->mfa_last_step);
        if ($step === null) return false;
        // Recorded only if no other request used this step or a later one meanwhile.
        if (DB::update('UPDATE public.users SET mfa_last_step = ? WHERE id = ? AND (mfa_last_step IS NULL OR mfa_last_step < ?)',
            [$step, $user->id, $step]) !== 1) return false;
        $user->forceFill(['mfa_last_step' => $step])->syncOriginalAttribute('mfa_last_step');
        return true;
    }

    public function useRecoveryCode(User $user, string $code): bool
    {
        return DB::update('UPDATE public.mfa_recovery_code SET used_at = now() WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$user->id, self::hash($code)]) === 1;
    }

    /** Eight new single-use codes, `XXXXX-XXXXX`. */
    public static function recoveryCodes(): array
    {
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = '';
            for ($j = 0; $j < 10; $j++) $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            $codes[] = substr($raw, 0, 5).'-'.substr($raw, 5);
        }
        return $codes;
    }

    /** Turns MFA on with a confirmed secret; returns the recovery codes, shown once. */
    public function enable(User $user, string $secret, int $step): array
    {
        $codes = self::recoveryCodes();
        DB::transaction(function () use ($user, $secret, $step, $codes) {
            $user->forceFill(['mfa_secret' => Crypt::encryptString($secret), 'mfa_enabled_at' => now(), 'mfa_last_step' => $step])->save();
            DB::delete('DELETE FROM public.mfa_recovery_code WHERE user_id = ?', [$user->id]);
            foreach ($codes as $code) {
                DB::insert('INSERT INTO public.mfa_recovery_code (user_id, code_hash) VALUES (?, ?)', [$user->id, self::hash($code)]);
            }
        });
        return $codes;
    }

    public static function hash(string $code): string
    {
        return hash('sha256', strtoupper(preg_replace('/[\s-]+/', '', $code)));
    }
}
