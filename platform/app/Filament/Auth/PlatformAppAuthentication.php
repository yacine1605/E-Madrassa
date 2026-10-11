<?php

namespace App\Filament\Auth;

use App\Support\MultiFactor;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use SensitiveParameter;

/**
 * Filament's TOTP, on the platform's own MFA store: the same secret and the same single-use
 * recovery codes as the API's (FR-IAM-02), whichever of the two the person enrolled with.
 */
final class PlatformAppAuthentication extends AppAuthentication
{
    /** @param array<string>|null $codes */
    public function saveRecoveryCodes(HasAppAuthenticationRecovery $user, #[SensitiveParameter] ?array $codes): void
    {
        $user->saveAppAuthenticationRecoveryCodes($codes === null ? null : array_map(MultiFactor::hash(...), $codes));
    }

    /** @return array<string> */
    public function generateRecoveryCodes(): array
    {
        return MultiFactor::recoveryCodes();
    }

    /** A code works once, even if two requests try it together: the database marks it used. */
    public function verifyRecoveryCode(#[SensitiveParameter] string $recoveryCode, ?HasAppAuthenticationRecovery $user = null): bool
    {
        $user ??= Filament::auth()->user();
        return app(MultiFactor::class)->useRecoveryCode($user, $recoveryCode);
    }
}
