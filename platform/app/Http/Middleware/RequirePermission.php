<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:a,b`: the route opens when the user holds one of these permissions, with any
 * scope (FR-IAM-03). The controller then narrows to the grant's scope (FR-IAM-04). Runs
 * inside SchoolContext. Every school route declares one (TST-02).
 */
final class RequirePermission
{
    public function __construct(private Permissions $permissions) {}

    public function handle(Request $request, Closure $next, string ...$required): Response
    {
        foreach ($required as $permission) {
            if ($this->permissions->can($permission)) return $next($request);
        }
        return self::denied();
    }

    public static function denied(): Response
    {
        return response()->json(['message' => 'Vous n’avez pas la permission nécessaire.', 'code' => 'permission_denied'], 403);
    }
}
