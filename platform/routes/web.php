<?php

use App\Http\Controllers\MeController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SiteController;
use Illuminate\Support\Facades\Route;

// Same-origin API for the application's own front end; CSRF protects every mutation.
Route::prefix('api')->group(function () {
    Route::get('csrf', fn () => ['csrf_token' => csrf_token()]);
    Route::post('session', [SessionController::class, 'login'])->middleware('throttle:login');
    Route::delete('session', [SessionController::class, 'logout']);

    Route::middleware('auth')->group(function () {
        Route::get('session/schools', [SessionController::class, 'schools']);
        Route::put('session/school', [SessionController::class, 'select']);

        // Everything below runs in the chosen school, under row-level security, and declares
        // the permission it requires (TST-02); only these two describe the session itself.
        Route::middleware('school')->group(function () {
            Route::get('school', [SchoolController::class, 'show']);
            Route::get('me', [MeController::class, 'show']);

            Route::middleware('permission:role.definition.manage,role.assignment.manage')->group(function () {
                Route::get('permissions', [PermissionController::class, 'index']);
                Route::get('roles', [RoleController::class, 'index']);
            });
            Route::middleware('permission:role.definition.manage')->group(function () {
                Route::post('roles', [RoleController::class, 'store']);
                Route::put('roles/{role}', [RoleController::class, 'update']);
                Route::put('roles/{role}/permissions', [RoleController::class, 'permissions']);
                Route::post('roles/{role}/archive', [RoleController::class, 'archive']);
            });
            Route::middleware('permission:role.assignment.manage')->group(function () {
                Route::get('role-assignments', [RoleAssignmentController::class, 'index']);
                Route::post('role-assignments', [RoleAssignmentController::class, 'store']);
                Route::post('role-assignments/{assignment}/archive', [RoleAssignmentController::class, 'archive']);
            });

            Route::middleware('module:sites_rooms')->group(function () {
                Route::get('sites', [SiteController::class, 'index'])->middleware('permission:referential.node.read');
                Route::middleware('permission:site.site.manage')->group(function () {
                    Route::post('sites', [SiteController::class, 'store']);
                    Route::put('sites/{site}', [SiteController::class, 'update']);
                    Route::post('sites/{site}/archive', [SiteController::class, 'archive']);
                });
            });
        });
    });
});
