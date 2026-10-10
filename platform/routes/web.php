<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountLinkController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ReferentialController;
use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SubjectController;
use Illuminate\Support\Facades\Route;

// Same-origin API for the application's own front end; CSRF protects every mutation.
Route::prefix('api')->group(function () {
    Route::get('csrf', fn () => ['csrf_token' => csrf_token()]);
    Route::delete('session', [SessionController::class, 'logout']);

    // Before sign-in (NFR-SEC-05): a flood limit per address; sign-in has its own progressive lockout.
    Route::middleware('throttle:public')->group(function () {
        Route::post('session', [SessionController::class, 'login']);
        Route::post('session/mfa', [SessionController::class, 'mfa']);
        Route::post('activation/check', [AccountLinkController::class, 'show'])->defaults('purpose', 'activation');
        Route::post('activation', [AccountLinkController::class, 'activate']);
        Route::post('recovery/check', [AccountLinkController::class, 'show'])->defaults('purpose', 'recovery');
        Route::post('recovery', [AccountLinkController::class, 'reset']);
        Route::post('recovery/request', [AccountLinkController::class, 'forgot'])->middleware('throttle:recovery');
    });

    Route::middleware(['auth', 'account'])->group(function () {
        // The person's own credentials, outside any school.
        Route::put('account/password', [CredentialController::class, 'password'])->name('account.password');
        Route::post('account/mfa', [CredentialController::class, 'startMfa']);
        Route::post('account/mfa/confirm', [CredentialController::class, 'confirmMfa']);

        Route::get('session/schools', [SessionController::class, 'schools']);
        Route::put('session/school', [SessionController::class, 'select']);

        // Everything below runs in the chosen school, under row-level security, and declares
        // the permission it requires (TST-02); only these two describe the session itself.
        Route::middleware('school')->group(function () {
            Route::get('school', [SchoolController::class, 'show']);
            Route::get('me', [MeController::class, 'show']);

            Route::get('settings', [SettingController::class, 'index'])->middleware('permission:tenant.settings.manage');
            Route::put('settings/{key}', [SettingController::class, 'update'])->middleware('permission:tenant.settings.manage');

            Route::get('accounts', [AccountController::class, 'index'])->middleware('permission:user.account.read');
            Route::middleware('permission:user.account.create')->group(function () {
                Route::post('accounts', [AccountController::class, 'store']);
                Route::post('accounts/import', [AccountController::class, 'import']);
                Route::post('accounts/{user}/invitation', [AccountController::class, 'invitation'])->whereNumber('user');
            });
            Route::middleware('permission:user.account.update')->group(function () {
                Route::put('accounts/{user}', [AccountController::class, 'update'])->whereNumber('user');
                Route::post('accounts/{user}/password', [AccountController::class, 'password'])->whereNumber('user');
            });
            Route::post('accounts/{user}/status', [AccountController::class, 'status'])->whereNumber('user')->middleware('permission:user.account.suspend');

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

            // Referential (FR-REF-01 to 05): read by every member of the school, managed by the referential's permission.
            Route::middleware('permission:referential.node.read')->group(function () {
                Route::get('referential/types', [ReferentialController::class, 'types']);
                Route::get('referential/nodes', [ReferentialController::class, 'nodes']);
                Route::get('subjects', [SubjectController::class, 'index']);
                Route::get('years', [CalendarController::class, 'index']);
                Route::get('custom-fields', [CustomFieldController::class, 'index']);
            });
            Route::middleware('permission:referential.node.manage')->group(function () {
                Route::put('referential/types/{type}', [ReferentialController::class, 'updateType']);
                Route::post('referential/nodes', [ReferentialController::class, 'store']);
                Route::put('referential/nodes/{node}', [ReferentialController::class, 'update']);
                Route::post('referential/nodes/{node}/archive', [ReferentialController::class, 'archive']);
                Route::post('years', [CalendarController::class, 'store']);
                Route::put('years/{year}', [CalendarController::class, 'update']);
                Route::post('years/{year}/status', [CalendarController::class, 'status']);
                Route::post('years/{year}/periods', [CalendarController::class, 'storePeriod']);
                Route::put('periods/{period}', [CalendarController::class, 'updatePeriod']);
                Route::post('periods/{period}/archive', [CalendarController::class, 'archivePeriod']);
            });
            Route::middleware('permission:subject.subject.manage')->group(function () {
                Route::post('subjects', [SubjectController::class, 'store']);
                Route::put('subjects/{subject}', [SubjectController::class, 'update']);
                Route::post('subjects/{subject}/archive', [SubjectController::class, 'archive']);
                Route::delete('subjects/{subject}', [SubjectController::class, 'destroy']);
            });
            Route::middleware('permission:custom_field.definition.manage')->group(function () {
                Route::post('custom-fields', [CustomFieldController::class, 'store']);
                Route::put('custom-fields/{field}', [CustomFieldController::class, 'update']);
                Route::post('custom-fields/{field}/archive', [CustomFieldController::class, 'archive']);
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
