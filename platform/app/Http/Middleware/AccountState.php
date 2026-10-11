<?php

namespace App\Http\Middleware;

use App\Exceptions\Refusal;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * FR-IAM-02 for every signed-in request: the session ends after the school's inactivity
 * delay or 12 hours in all; a provisional password is changed before anything else.
 */
final class AccountState
{
    public function handle(Request $request, Closure $next): Response
    {
        $session = $request->session();
        $now = now()->getTimestamp();
        $idle = 60 * (int) ($session->get('school.idle_minutes') ?? config('platform.session_idle_minutes'));
        $started = (int) $session->get('auth.started_at', 0);
        $seen = (int) $session->get('auth.seen_at', 0);
        if ($now - $started > 60 * config('platform.session_absolute_minutes') || $now - $seen > $idle) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();
            // The API answers; a page of the console goes back to its sign-in.
            if (!$request->expectsJson()) return redirect()->guest(Filament::getCurrentPanel()?->getLoginUrl() ?? '/');
            return Refusal::response('session_expired', 401);
        }
        $session->put('auth.seen_at', $now);
        if ($request->user()->must_change_password && !$request->routeIs('account.password')) {
            return Refusal::response('password_change_required', 403);
        }
        return $next($request);
    }
}
