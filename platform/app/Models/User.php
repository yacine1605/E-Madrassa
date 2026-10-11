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
use App\Support\Credentials;
use App\Support\SchoolMemberships;
use Illuminate\Database\Eloquent\Builder;

/**
 * A person: one sign-in, possibly in several schools, possibly an operator of the publisher's
 * console. Their MFA is the same everywhere: the secret encrypted in `mfa_secret`, the recovery
 * codes hashed in `public.mfa_recovery_code` (FR-IAM-02). The model holds what the schools'
 * application may read; the credentials go through App\Support\Credentials (SECURITY-REVIEW.md, F2).
 */
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = ['name', 'email', 'password'];

    /** The columns `school_runtime` may read (deploy/roles.sql): never a password hash, an MFA secret or a token. */
    public const COLUMNS = ['id', 'name', 'email', 'email_verified_at', 'locale', 'must_change_password', 'password_changed_at',
        'mfa_enabled_at', 'has_password', 'created_at', 'updated_at'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token', 'mfa_secret', 'mfa_last_step'];

    /** No "remember me" (CredentialsUserProvider): no token to read or write. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /** Every query of the model, with or without scopes (fresh, refresh), reads those columns unless it names its own. */
    public function newModelQuery(): Builder
    {
        return parent::newModelQuery()->select(array_map(fn ($column) => $this->qualifyColumn($column), self::COLUMNS));
    }

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
        if ($panel->getId() === 'console') return $this->isOperator();
        // Support access is checked again by SchoolContext against the current consent.
        if ($panel->getId() === 'school') return session()->has('school.tenant') || app(SchoolMemberships::class)->forUser($this->id)->isNotEmpty()
            || (session()->has('support.grant') && $this->isOperator());
        return false;
    }

    public function isOperator(): bool
    {
        return (bool) DB::connection('console')->selectOne('SELECT 1 AS operator FROM platform.operator WHERE user_id = ? AND revoked_at IS NULL', [$this->id]);
    }

    public function getAppAuthenticationSecret(): ?string
    {
        $secret = app(Credentials::class)->mfa($this->id)?->secret;
        return $secret ? Crypt::decryptString($secret) : null;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        app(Credentials::class)->setMfa($this->id, $secret === null ? null : Crypt::encryptString($secret));
        $this->forceFill(['mfa_enabled_at' => $secret === null ? null : now()])->syncOriginalAttribute('mfa_enabled_at');
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email ?? $this->name;
    }

    /** The hashes of the codes not yet used. */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return app(Credentials::class)->recoveryCodes($this->id) ?: null;
    }

    /** @param array<string>|null $codes hashes made by MultiFactor::hash() */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        app(Credentials::class)->setRecoveryCodes($this->id, array_values($codes ?? []));
    }
}
