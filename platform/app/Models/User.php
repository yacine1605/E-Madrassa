<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * A person: one sign-in, possibly in several schools, possibly an operator of the publisher's
 * console. Their MFA is the same everywhere: the secret encrypted in `mfa_secret`, the recovery
 * codes hashed in `public.mfa_recovery_code` (FR-IAM-02).
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_last_step'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'mfa_enabled_at' => 'datetime',
            'mfa_last_step' => 'integer',
        ];
    }

    /** The console opens to its operators only (the `super_admin` of section 3.2). */
    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'console' && $this->isOperator();
    }

    public function isOperator(): bool
    {
        return (bool) DB::connection('console')->selectOne('SELECT 1 AS operator FROM platform.operator WHERE user_id = ? AND revoked_at IS NULL', [$this->id]);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->mfa_secret ? Crypt::decryptString($this->mfa_secret) : null;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['mfa_secret' => $secret === null ? null : Crypt::encryptString($secret), 'mfa_enabled_at' => $secret === null ? null : now(),
            'mfa_last_step' => null])->save();
        if ($secret === null) DB::delete('DELETE FROM public.mfa_recovery_code WHERE user_id = ?', [$this->id]);
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email ?? $this->name;
    }

    /** The hashes of the codes not yet used. */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        $codes = array_column(DB::select('SELECT code_hash FROM public.mfa_recovery_code WHERE user_id = ? AND used_at IS NULL', [$this->id]), 'code_hash');
        return $codes ?: null;
    }

    /** @param array<string>|null $codes hashes made by MultiFactor::hash() */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        DB::transaction(function () use ($codes) {
            DB::delete('DELETE FROM public.mfa_recovery_code WHERE user_id = ?', [$this->id]);
            foreach ($codes ?? [] as $hash) DB::insert('INSERT INTO public.mfa_recovery_code (user_id, code_hash) VALUES (?, ?)', [$this->id, $hash]);
        });
    }
}
