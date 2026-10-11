<?php

namespace App\Support;

use App\Mail\AccountLink;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Activation and recovery links (FR-IAM-01, FR-IAM-02). The token is random, sent once and
 * stored only as its hash; it never reaches a log (NFR-SEC-06).
 */
final class AccountLinks
{
    /** @return array{0: string, 1: string} the token and its hash */
    public static function token(): array
    {
        $token = bin2hex(random_bytes(32));
        return [$token, self::hash($token)];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function url(string $purpose, string $token): string
    {
        return config('platform.front_url').'/'.($purpose === 'activation' ? 'activation' : 'recovery').'#token='.$token;
    }

    /**
     * Queues the link: the request answers at once, the same for a known and an unknown address
     * (no timing difference), and a failed sending is retried by the queue. In the person's
     * language (FR-I18-01).
     */
    public static function queue(string $purpose, string $email, string $name, string $token, string $locale): void
    {
        Mail::to($email, $name)->locale($locale)->queue(new AccountLink($purpose, $name, self::url($purpose, $token)));
    }

    /** Sends the link; false when sending failed, so that an administrator may hand it over another way. */
    public static function send(string $purpose, string $email, string $name, string $token, string $locale, ?string $school = null): bool
    {
        try {
            Mail::to($email, $name)->locale($locale)->send(new AccountLink($purpose, $name, self::url($purpose, $token), $school));
            return true;
        } catch (Throwable $e) {
            Log::warning('Account link not sent', ['purpose' => $purpose, 'error' => $e::class]);
            return false;
        }
    }
}
