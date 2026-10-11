<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * A person's credentials: password hash, MFA secret (encrypted by the application), last TOTP
 * step and recovery codes (FR-IAM-02). Read and written through `platform_auth`, the only role
 * that sees them; the schools' application, `school_runtime`, sees none (SECURITY-REVIEW.md, F2
 * and F3). Used by the sign-in and by the person's own password and MFA, nothing else.
 */
final class Credentials
{
    private function db(): Connection
    {
        return DB::connection('auth');
    }

    public function passwordHash(int $user): ?string
    {
        return $this->db()->table('users')->where('id', $user)->value('password');
    }

    /** A new hash, chosen by the person: no longer provisional. */
    public function setPassword(int $user, #[SensitiveParameter] string $hash): void
    {
        $this->db()->table('users')->where('id', $user)->update(['password' => $hash, 'must_change_password' => false,
            'password_changed_at' => now(), 'updated_at' => now()]);
    }

    /** The same password, hashed again with the current parameters. */
    public function rehash(int $user, #[SensitiveParameter] string $hash): void
    {
        $this->db()->table('users')->where('id', $user)->update(['password' => $hash]);
    }

    /** @return object{secret: ?string, last_step: ?int}|null the secret still encrypted */
    public function mfa(int $user): ?object
    {
        $row = $this->db()->table('users')->where('id', $user)->first(['mfa_secret', 'mfa_last_step']);
        return $row ? (object) ['secret' => $row->mfa_secret, 'last_step' => $row->mfa_last_step === null ? null : (int) $row->mfa_last_step] : null;
    }

    /** Records a code's step, only if no other request used this step or a later one meanwhile. */
    public function recordStep(int $user, int $step): bool
    {
        return $this->db()->update('UPDATE public.users SET mfa_last_step = ? WHERE id = ? AND (mfa_last_step IS NULL OR mfa_last_step < ?)',
            [$step, $user, $step]) === 1;
    }

    /**
     * MFA on with this encrypted secret and these recovery codes' hashes, or off when the
     * secret is null (the codes go with it).
     *
     * @param list<string>|null $codeHashes
     */
    public function setMfa(int $user, #[SensitiveParameter] ?string $encryptedSecret, ?int $step = null, ?array $codeHashes = null): void
    {
        $this->db()->transaction(function () use ($user, $encryptedSecret, $step, $codeHashes) {
            $this->db()->table('users')->where('id', $user)->update(['mfa_secret' => $encryptedSecret,
                'mfa_enabled_at' => $encryptedSecret === null ? null : now(), 'mfa_last_step' => $step, 'updated_at' => now()]);
            if ($encryptedSecret === null || $codeHashes !== null) $this->setRecoveryCodes($user, $codeHashes ?? []);
        });
    }

    /** @return list<string> the hashes of the codes not used yet */
    public function recoveryCodes(int $user): array
    {
        return $this->db()->table('mfa_recovery_code')->where('user_id', $user)->whereNull('used_at')->pluck('code_hash')->all();
    }

    /** @param list<string> $codeHashes */
    public function setRecoveryCodes(int $user, #[SensitiveParameter] array $codeHashes): void
    {
        $this->db()->transaction(function () use ($user, $codeHashes) {
            $this->db()->table('mfa_recovery_code')->where('user_id', $user)->delete();
            foreach ($codeHashes as $hash) $this->db()->table('mfa_recovery_code')->insert(['user_id' => $user, 'code_hash' => $hash]);
        });
    }

    public function useRecoveryCode(int $user, #[SensitiveParameter] string $codeHash): bool
    {
        return $this->db()->table('mfa_recovery_code')->where(['user_id' => $user, 'code_hash' => $codeHash])->whereNull('used_at')
            ->update(['used_at' => now()]) === 1;
    }
}
