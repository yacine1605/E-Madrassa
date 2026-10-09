<?php
namespace App\Http\Middleware;

use App\School\Modules\ModuleRegistry;
use Closure;
use Illuminate\Http\Request;

final class SchoolModule
{
    public function handle(Request $request, Closure $next, string $module)
    {
        abort_unless(config('school.backend') && app(ModuleRegistry::class)->enabled($module), 404);
        return $next($request);
    }
}
