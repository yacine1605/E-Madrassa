<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountLinkController;
use App\Http\Controllers\AuditController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CredentialController;
use App\Http\Controllers\CustomFieldController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\FileController;
use App\Http\Controllers\GuardianController;
use App\Http\Controllers\GroupMembershipController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\JobController;
use App\Http\Controllers\MeController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\PreferenceController;
use App\Http\Controllers\ProgramController;
use App\Http\Controllers\ProgramSubjectController;
use App\Http\Controllers\ReferentialController;
use App\Http\Controllers\RoleAssignmentController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SchoolController;
use App\Http\Controllers\SessionController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\SiteController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\StudentController;
use App\Http\Controllers\StudentPhotoController;
use App\Http\Controllers\StudyGroupController;
use App\Http\Controllers\SupportGrantController;
use App\Http\Controllers\SupportSessionController;
use Illuminate\Support\Facades\Route;

// The deployment URL opens the school sign-in by default; publisher operators use /console.
Route::redirect('/', '/school/login');

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

    // FR-FIC-01 AC3: a temporary signed link opens one file without a session, until it expires.
    Route::get('files/link/{tenant}/{file}', [FileController::class, 'linked'])->name('files.link')
        ->whereUuid(['tenant', 'file'])->middleware(['signed', 'throttle:public']);

    Route::middleware(['auth', 'account'])->group(function () {
        // The person's own credentials, outside any school.
        Route::put('account/password', [CredentialController::class, 'password'])->name('account.password');
        Route::post('account/mfa', [CredentialController::class, 'startMfa']);
        Route::post('account/mfa/confirm', [CredentialController::class, 'confirmMfa']);
        Route::put('account/locale', [PreferenceController::class, 'locale']);

        // FR-PLT-04: an operator works in a school that consented, read-only.
        Route::post('support/session', [SupportSessionController::class, 'open']);
        Route::delete('support/session', [SupportSessionController::class, 'close']);

        Route::get('session/schools', [SessionController::class, 'schools']);
        Route::put('session/school', [SessionController::class, 'select']);

        // Everything below runs in the chosen school, under row-level security, and declares
        // the permission it requires (TST-02); only these two describe the session itself.
        Route::middleware('school')->group(function () {
            Route::get('school', [SchoolController::class, 'show']);
            Route::get('me', [MeController::class, 'show']);

            // The person's own jobs and notifications: row-level security shows them nothing else.
            Route::get('jobs', [JobController::class, 'index']);
            Route::get('jobs/{job}', [JobController::class, 'show']);
            Route::post('jobs/{job}/cancel', [JobController::class, 'cancel']);
            Route::post('jobs/{job}/retry', [JobController::class, 'retry']);
            Route::get('notifications', [NotificationController::class, 'index']);
            Route::post('notifications/read', [NotificationController::class, 'readAll']);
            Route::post('notifications/{notification}/read', [NotificationController::class, 'read']);
            Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
            Route::put('notifications/preferences', [NotificationController::class, 'updatePreferences']);
            Route::middleware('permission:tenant.settings.manage')->group(function () {
                Route::get('deliveries/failed', [NotificationController::class, 'failures']);
                Route::post('deliveries/{delivery}/retry', [NotificationController::class, 'retry']);
            });

            Route::get('audit', [AuditController::class, 'index'])->middleware('permission:audit.log.read');

            Route::get('support-grants', [SupportGrantController::class, 'index'])->middleware('permission:support.access.grant,audit.log.read');
            Route::middleware('permission:support.access.grant')->group(function () {
                Route::post('support-grants', [SupportGrantController::class, 'store']);
                Route::post('support-grants/{grant}/revoke', [SupportGrantController::class, 'revoke']);
            });

            Route::middleware('permission:import.job.create')->group(function () {
                Route::get('imports/templates/{kind}', [ImportController::class, 'template']);
                Route::post('imports', [ImportController::class, 'store']);
                Route::post('imports/{batch}/confirm', [ImportController::class, 'confirm']);
                Route::post('imports/{batch}/rollback', [ImportController::class, 'rollback']);
            });
            Route::middleware('permission:import.job.create,import.job.read')->group(function () {
                Route::get('imports', [ImportController::class, 'index']);
                Route::get('imports/{batch}', [ImportController::class, 'show']);
                Route::get('imports/{batch}/rows', [ImportController::class, 'rows']);
                Route::get('imports/{batch}/report', [ImportController::class, 'report']);
            });

            Route::middleware('permission:export.job.create')->group(function () {
                Route::post('exports', [ExportController::class, 'store']);
                Route::get('exports', [ExportController::class, 'index']);
            });

            Route::middleware(FileController::WRITERS)->group(function () {
                Route::post('files', [FileController::class, 'store']);
                Route::post('files/{file}/archive', [FileController::class, 'archive']);
            });
            Route::middleware(FileController::READERS)->group(function () {
                Route::get('files/{file}', [FileController::class, 'show']);
                Route::get('files/{file}/content', [FileController::class, 'content']);
                Route::post('files/{file}/link', [FileController::class, 'link']);
            });

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

            Route::middleware('module:enrollment_groups')->group(function () {
                Route::middleware('permission:program.program.read')->group(function () {
                    Route::get('programs', [ProgramController::class, 'index']);
                    Route::get('programs/{program}', [ProgramController::class, 'show']);
                    Route::get('programs/{program}/prices', [ProgramController::class, 'prices']);
                    Route::get('programs/{program}/subjects', [ProgramSubjectController::class, 'index']);
                    Route::get('program-subjects/{line}/volumes', [ProgramSubjectController::class, 'volumes']);
                });
                Route::post('programs', [ProgramController::class, 'store'])->middleware('permission:program.program.manage');
                Route::put('programs/{program}', [ProgramController::class, 'update'])->middleware('permission:program.program.manage');
                // FR-PRG-02: a programme's subjects and their volumes.
                Route::middleware('permission:program.subject.manage')->group(function () {
                    Route::post('programs/{program}/subjects', [ProgramSubjectController::class, 'store']);
                    Route::put('program-subjects/{line}', [ProgramSubjectController::class, 'update']);
                    Route::post('program-subjects/{line}/end', [ProgramSubjectController::class, 'end']);
                    Route::delete('program-subjects/{line}', [ProgramSubjectController::class, 'destroy']);
                });
                Route::middleware('permission:group.group.read')->group(function () {
                    Route::get('groups', [StudyGroupController::class, 'index']);
                    Route::get('groups/{group}', [StudyGroupController::class, 'show']);
                    Route::get('groups/{group}/memberships', [GroupMembershipController::class, 'index']);
                });
                Route::post('groups', [StudyGroupController::class, 'store'])->middleware('permission:group.group.manage');
                Route::put('groups/{group}', [StudyGroupController::class, 'update'])->middleware('permission:group.group.manage');
                Route::middleware('permission:group.membership.manage')->group(function () {
                    Route::post('groups/{group}/memberships', [GroupMembershipController::class, 'store']);
                    Route::post('group-memberships/{membership}/end', [GroupMembershipController::class, 'end']);
                    Route::post('group-memberships/{membership}/transfer', [GroupMembershipController::class, 'transfer']);
                });
                Route::middleware('permission:enrollment.enrollment.read')->group(function () {
                    Route::get('enrollments', [EnrollmentController::class, 'index']);
                    Route::get('enrollments/{enrollment}', [EnrollmentController::class, 'show']);
                    Route::get('enrollments/{enrollment}/history', [EnrollmentController::class, 'history']);
                    Route::get('enrollments/{enrollment}/groups', [EnrollmentController::class, 'groups']);
                });
                Route::post('enrollments', [EnrollmentController::class, 'store'])->middleware('permission:enrollment.enrollment.create');
                Route::post('enrollments/{enrollment}/validate', [EnrollmentController::class, 'validateEnrollment'])->middleware('permission:enrollment.enrollment.validate');
                Route::post('enrollments/{enrollment}/suspend', [EnrollmentController::class, 'suspend'])->middleware('permission:enrollment.enrollment.suspend');
                Route::post('enrollments/{enrollment}/cancel', [EnrollmentController::class, 'cancel'])->middleware('permission:enrollment.enrollment.cancel');
                Route::post('enrollments/{enrollment}/transfer', [EnrollmentController::class, 'transfer'])->middleware('permission:enrollment.enrollment.cancel');
            });

            Route::middleware('module:students_crm')->group(function () {
                Route::middleware('permission:student.record.read')->group(function () {
                    Route::get('students', [StudentController::class, 'index']);
                    Route::get('students/{student}', [StudentController::class, 'show']);
                    Route::post('students/duplicates', [StudentController::class, 'duplicates']);
                    Route::get('students/{student}/photo', [StudentPhotoController::class, 'show']);
                });
                Route::post('students', [StudentController::class, 'store'])->middleware('permission:student.record.create');
                Route::put('students/{student}', [StudentController::class, 'update'])->middleware('permission:student.record.update');
                Route::post('students/{student}/status', [StudentController::class, 'status'])->middleware('permission:student.record.update');
                Route::middleware('permission:student.record.update')->group(function () {
                    Route::post('students/{student}/photo', [StudentPhotoController::class, 'store']);
                    Route::delete('students/{student}/photo', [StudentPhotoController::class, 'destroy']);
                });
                Route::post('students/{student}/archive', [StudentController::class, 'archive'])->middleware('permission:student.record.archive');
                Route::middleware('permission:guardian.record.read')->group(function () {
                    Route::get('guardians', [GuardianController::class, 'index']);
                    Route::get('guardians/{guardian}', [GuardianController::class, 'show']);
                    Route::post('guardians/duplicates', [GuardianController::class, 'duplicates']);
                    Route::get('students/{student}/guardians', [GuardianController::class, 'links']);
                });
                Route::middleware('permission:guardian.record.create')->group(function () {
                    Route::post('guardians', [GuardianController::class, 'store']);
                    Route::post('students/{student}/guardians', [GuardianController::class, 'link']);
                });
                Route::middleware('permission:guardian.record.update')->group(function () {
                    Route::put('guardians/{guardian}', [GuardianController::class, 'update']);
                    Route::put('students/{student}/guardians/{link}', [GuardianController::class, 'updateLink']);
                    Route::post('students/{student}/guardians/{link}/archive', [GuardianController::class, 'unlink']);
                });
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
