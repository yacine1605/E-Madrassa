<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Runs both the first page and every verified Livewire update in the server-selected school. */
final class SchoolPanelContext
{
    /** The personal pages, which must work before choosing a school, changing a provisional
     * password or enrolling in MFA. Their handlers never read a school's tables. */
    public const PERSONAL = ['filament.school.pages.choose-school', 'filament.school.pages.credentials', 'filament.school.auth.logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::PERSONAL)) return $next($request);
        if (!$request->session()->has('school.tenant')) return redirect('/school/choose-school');
        $response = app(SchoolContext::class)->handle($request, $next);
        if ($response->getStatusCode() < 400) return $response;
        $code = method_exists($response, 'getData') ? $response->getData(true)['code'] ?? null : null;
        if ($code === 'mfa_setup_required') return redirect('/school/credentials');
        if ($code === 'support_ended') return redirect('/console');
        if (in_array($code, ['membership_inactive', 'tenant_suspended'], true)) return redirect('/school/login');
        // Livewire ignores non-redirect responses from persistent middleware: throw to stop it.
        throw new HttpResponseException($response);
    }
}
