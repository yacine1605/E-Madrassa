<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-I18-01: every request speaks the person's language. In a school, SchoolContext resolves it
 * and keeps it in the session; before that, the person's own choice; before sign-in, the
 * browser's preference among the available languages.
 */
final class Localize
{
    public function handle(Request $request, Closure $next): Response
    {
        self::resolve($request);
        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        return $response;
    }

    public static function resolve(Request $request): void
    {
        $kept = $request->hasSession() ? $request->session()->get('locale') : null;
        Locales::apply(Locales::usable($kept ?? $request->user()?->locale ?? Locales::fromRequest($request)));
    }
}
