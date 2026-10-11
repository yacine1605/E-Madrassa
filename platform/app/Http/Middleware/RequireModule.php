<?php

namespace App\Http\Middleware;

use App\Exceptions\Refusal;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** ARC-04, BR-20, FR-PLT-02 AC2: a disabled module's API does not exist for the school. Runs inside SchoolContext. */
final class RequireModule
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        if (!DB::selectOne('SELECT school.module_enabled(?) AS enabled', [$module])->enabled) {
            return Refusal::response('module_disabled', 403);
        }
        return $next($request);
    }
}
