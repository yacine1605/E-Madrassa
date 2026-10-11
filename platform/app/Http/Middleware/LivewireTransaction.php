<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Livewire's persistent middleware finishes BEFORE it hydrates/calls/renders components.
 * Keep its SchoolContext SET LOCAL values alive until the entire update finishes, including
 * rendering. A savepoint in SchoolContext alone would otherwise commit too early (TST-01).
 * No tenant is inferred from unverified snapshots or request headers here.
 */
final class LivewireTransaction
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->routeIs('*livewire.update')) return $next($request);
        return DB::transaction(function () use ($request, $next) {
            $response = $next($request);
            if ($response->exception ?? null) throw $response->exception;
            return $response;
        });
    }
}
