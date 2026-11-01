<?php

return [
    // Dedicated installation (ND-06): the slug of the only school this deployment serves.
    // Empty for the shared SaaS, where a user opens any school they are an active member of.
    'dedicated_school' => env('PLATFORM_DEDICATED_SCHOOL') ?: null,

    // Database limits applied to every school request.
    'lock_timeout' => env('PLATFORM_LOCK_TIMEOUT', '3s'),
    'statement_timeout' => env('PLATFORM_STATEMENT_TIMEOUT', '10s'),

    // FR-IAM-02. Inactivity: the school's `session.idle_minutes` setting (15 to 120), this value
    // until a school is open. A session never outlives the absolute limit.
    'session_idle_minutes' => 30,
    'session_absolute_minutes' => 12 * 60,

    // FR-IAM-02: after this many consecutive failures, an account or an address waits, first
    // `lockout_first_minutes`, doubling at each new failure up to `lockout_max_minutes`.
    'lockout_threshold' => 5,
    // A school's pupils often share one public address: raise this one if they lock each other out.
    'lockout_ip_threshold' => (int) env('PLATFORM_LOCKOUT_IP_THRESHOLD', 5),
    'lockout_first_minutes' => 1,
    'lockout_max_minutes' => 60,

    // NFR-SEC-03: true reports what the Content Security Policy would block without blocking it
    // (App\Http\Middleware\ContentSecurityPolicy), should a screen break in production.
    'csp_report_only' => (bool) env('PLATFORM_CSP_REPORT_ONLY', false),

    // A trial, never for production (SECURITY-REVIEW.md, F4): true serves Livewire's CSP build of
    // Alpine (config/livewire.php) and takes 'unsafe-eval' out of the policy. With Filament 5.10
    // every screen then breaks; after each upgrade of Filament, the browser acceptance tells
    // whether it still does (`PLATFORM_CSP_STRICT=true sh deploy/acceptance-test.sh`).
    'csp_strict' => (bool) env('PLATFORM_CSP_STRICT', false),

    // Second step of a sign-in with MFA: how long the first step stays valid.
    'mfa_pending_seconds' => 300,
    'mfa_issuer' => env('PLATFORM_MFA_ISSUER', env('APP_NAME', 'Madrassa')),

    // FR-JOB-01: the kinds of school jobs and the task that runs each.
    'tasks' => [
        'accounts.import' => App\Tasks\ImportAccounts::class,
        'exports.generate' => App\Tasks\GenerateExport::class,
        'imports.preview' => App\Tasks\PreviewImport::class,
        'imports.run' => App\Tasks\RunImport::class,
    ],

    // FR-IMP-01: what can be imported from a file.
    'imports' => [
        'accounts' => App\Imports\AccountsImporter::class,
        'referential' => App\Imports\ReferentialImporter::class,
        // Lot 2, in this order: the pupils, their guardians, then their enrolments.
        'students' => App\Imports\StudentsImporter::class,
        'guardians' => App\Imports\GuardiansImporter::class,
        'enrollments' => App\Imports\EnrollmentsImporter::class,
        // The staff, then their qualifications.
        'staff' => App\Imports\StaffImporter::class,
        'qualifications' => App\Imports\QualificationsImporter::class,
        // The prospects (section 3.3: the front office imports « élèves, CRM »).
        'leads' => App\Imports\LeadsImporter::class,
        // The programmes of a year, then their groups (section 3.3: the head of studies imports « staff, groupes »).
        'programs' => App\Imports\ProgramsImporter::class,
        'groups' => App\Imports\GroupsImporter::class,
    ],

    // FR-IMP-02: what can be exported, and from how many rows an export runs in the background.
    'exports' => [
        'accounts' => App\Exports\AccountsExport::class,
        'referential' => App\Exports\ReferentialExport::class,
        // FR-FIN-06: the arrears, for whoever reads the payments.
        'arrears' => App\Exports\ArrearsExport::class,
        // FR-DEP-02: the expenses, for whoever reads them.
        'expenses' => App\Exports\ExpensesExport::class,
        // FR-TDB-03: the dashboards, as their screens show them, for whoever reads them.
        'finance_dashboard' => App\Exports\FinanceDashboardExport::class,
        'operations_dashboard' => App\Exports\OperationsDashboardExport::class,
        // FR-LMS-04: a group's gradebook in a subject, for its teacher or the pedagogy manager.
        'gradebook' => App\Exports\GradebookExport::class,
    ],
    'export_background_rows' => 2000,

    // Activation and recovery links point to the school panel's pages; the token travels in the
    // fragment, which browsers never send to a server or put in a Referer header.
    'front_url' => rtrim((string) env('PLATFORM_FRONT_URL', rtrim((string) env('APP_URL', 'http://localhost'), '/').'/school'), '/'),
];
