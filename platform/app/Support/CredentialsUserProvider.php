<?php

namespace App\Support;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Hashing\Hasher;
use SensitiveParameter;

/**
 * Laravel's and Filament's sign-in, with the password checked through `platform_auth`
 * (Credentials): the user model the schools' application reads holds no hash. No "remember me":
 * a session ends at its time limits (config/platform.php), and no token outlives it.
 */
final class CredentialsUserProvider extends EloquentUserProvider
{
    public function __construct(Hasher $hasher, string $model, private Credentials $credentials)
    {
        parent::__construct($hasher, $model);
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
