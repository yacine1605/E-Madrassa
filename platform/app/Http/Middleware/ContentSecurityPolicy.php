<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * NFR-SEC-03 (SECURITY-REVIEW.md, F4): scripts come from the platform itself, or inline with
 * this response's nonce, which Livewire and Filament put on theirs; anything injected into a
 * page, inline or from elsewhere, does not run. Alpine and Livewire's `@script` blocks still
 * evaluate their expressions (`'unsafe-eval'`): Filament 5.10 cannot run Alpine's CSP build,
 * whose trial `PLATFORM_CSP_STRICT` serves. Filament writes style attributes (`'unsafe-inline'`
 * for styles only). `PLATFORM_CSP_REPORT_ONLY` reports without blocking, for a screen that
 * would break in production.
 */
final class ContentSecurityPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);
        $header = config('platform.csp_report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
        if (!$response->headers->has($header)) $response->headers->set($header, self::policy($nonce));
        return $response;
    }

    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-$nonce'".(config('platform.csp_strict') ? '' : " 'unsafe-eval'"),
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
