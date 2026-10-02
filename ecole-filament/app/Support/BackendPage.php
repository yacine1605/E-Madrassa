<?php
namespace App\Support;

use Filament\Pages\Page;

/** Browser actions use the session-authenticated API and its per-request tenant checks. */
abstract class BackendPage extends Page
{
    public static string $module;
    protected string $view = 'filament.pages.backend';

    public function getTitle(): string
    {
        return DemoStore::modules()[static::$module]['title'];
    }

    public function backendConfig(): array
    {
        $map = [
            'dashboard'=>['students','cohorts','offerings','enrollments'],
            'students'=>['students','guardians','student_guardians'],
            'formations'=>['programs','levels','periods','offerings'],
            'groups'=>['cohorts','teacher_assignments'], 'subjects'=>['subjects','curriculum'],
            'rooms'=>['campuses','rooms'], 'enrollments'=>['enrollments','invoices'],
            'payments'=>['payments','refunds','invoice_balances'],
            'schedule'=>['sessions'], 'attendance'=>['attendance'],
            'grades'=>['grades'], 'assessments'=>['assessments'],
            'teachers'=>['staff','teacher_qualifications','teacher_assignments','contracts','work_logs','payroll_runs'], 'staff'=>['staff','contracts'],
            'hours'=>['work_logs','contracts'], 'payroll'=>['payroll_runs'],
            'expenses'=>['expenses','disbursements'], 'lessons'=>['lesson_versions'],
            'announcements'=>['announcements'], 'audit'=>['audit_events'],
            'finance'=>['invoice_balances','invoices','payments','refunds','expenses','disbursements'],
            'analytics'=>['invoice_balances'],
        ];
        return [
            'resources'=>$map[static::$module] ?? [],
            'catalog'=>config('school_backend.catalog', []),
            'mutable'=>config('school_backend.mutable_catalog', []),
            'read'=>config('school_backend.read', []),
            'module'=>static::$module,
            'description'=>DemoStore::modules()[static::$module]['description'] ?? '',
            'labels'=>config('backend_ui.labels'),
            'operations'=>config('backend_ui.operations'),
            'relations'=>config('backend_ui.relations'),
        ];
    }
}
