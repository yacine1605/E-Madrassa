<?php

namespace App\Filament\Console\Pages;

use App\Support\LoginThrottle;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Filament's sign-in (timebox, MFA challenge) with the platform's progressive lockout per
 * account and per address (FR-IAM-02), and the session's clock for its time limits. No
 * "remember me": a session ends at its limits (CredentialsUserProvider).
 */
final class ConsoleLogin extends Login
{
    public function form(Schema $schema): Schema
    {
        return $schema->components([$this->getEmailFormComponent(), $this->getPasswordFormComponent()]);
    }

    public function authenticate(): ?LoginResponse
    {
        $login = (string) ($this->data['email'] ?? '');
        $throttle = app(LoginThrottle::class);
        if ($throttle->wait($login, (string) request()->ip())) {
            Notification::make()->title(__('errors.too_many_attempts'))->danger()->send();
            return null;
        }
        try {
            $response = parent::authenticate();
        } catch (ValidationException $e) {
            $throttle->failed($login, (string) request()->ip());
            throw $e;
        }
        if ($response) {
            $throttle->succeeded($login);
            session()->put(['auth.started_at' => now()->getTimestamp(), 'auth.seen_at' => now()->getTimestamp()]);
        }
        return $response;
    }
}
