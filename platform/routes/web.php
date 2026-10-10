<?php

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

        // Everything below runs in the chosen school, under row-level security.
        Route::middleware('school')->group(function () {
            Route::get('school', [SchoolController::class, 'show']);
            Route::middleware('module:sites_rooms')->group(function () {
                Route::get('sites', [SiteController::class, 'index']);
                Route::post('sites', [SiteController::class, 'store']);
                Route::put('sites/{site}', [SiteController::class, 'update']);
                Route::post('sites/{site}/archive', [SiteController::class, 'archive']);
            });
        });
    });
});
