<?php
namespace App\Support;

use Filament\Pages\Page;

/** Browser actions use the session-authenticated API and its per-request tenant checks. */
abstract class BackendPage extends Page
{
    use \App\Support\Concerns\HasModuleAccess;
    public static string $module;
    protected string $view = 'filament.pages.backend';

    public function getTitle(): string
    {
        return DemoStore::modules()[static::$module]['title'];
    }

    public function backendConfig(): array
    {
        $modules = app(\App\School\Modules\ModuleRegistry::class);
        return [
            'resources'=>$modules->pageResources(static::$module),
            'teacherPaymentsAvailable'=>$modules->canAccessPage('teacher-payments', request()->attributes->get('school.module.grants', [])),
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
