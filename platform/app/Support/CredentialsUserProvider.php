<?php

namespace App\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Laravel's and Filament's sign-in, with the password checked through `platform_auth`
 * (Credentials): the user model the schools' application reads holds no hash. No "remember me":
 * a session ends at its time limits (config/platform.php), and no token outlives it. A person
 * is read in their own context: before a school, the database shows nobody else (F2).
 */
final class CredentialsUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, string $model, private Credentials $credentials, private DatabaseContext $context)
    {
        parent::__construct($hasher, $model);
    }

    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->context->reading(null, (int) $identifier, fn () => parent::retrieveById($identifier));
    }

    /** Filament's console sign-in names the person by email; the database finds them (platform.find_login). */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        $email = $credentials['email'] ?? null;
        if (!is_string($email) || $email === '') return null;
        $id = DB::selectOne('SELECT platform.find_login(?, NULL) AS id', [$email])->id;
        return $id ? $this->retrieveById($id) : null;
    }

    public function validateCredentials(Authenticatable $user, #[SensitiveParameter] array $credentials): bool
    {
        $plain = $credentials['password'] ?? null;
        $hash = $this->credentials->passwordHash((int) $user->getAuthIdentifier());
        return $plain !== null && $hash !== null && $this->hasher->check($plain, $hash);
    }

    public function rehashPasswordIfRequired(Authenticatable $user, #[SensitiveParameter] array $credentials, bool $force = false): void
    {
        $hash = $this->credentials->passwordHash((int) $user->getAuthIdentifier());
        if ($hash === null || (!$force && !$this->hasher->needsRehash($hash))) return;
        $this->credentials->rehash((int) $user->getAuthIdentifier(), $this->hasher->make($credentials['password']));
    }

    public function retrieveByToken($identifier, #[SensitiveParameter] $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, #[SensitiveParameter] $token): void {}
}
