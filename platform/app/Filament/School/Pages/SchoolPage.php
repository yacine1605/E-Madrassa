<?php

namespace App\Filament\School\Pages;

use App\Support\CurrentSchool;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use App\Http\Controllers\SchoolController;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

abstract class SchoolPage extends Page
{
    protected static bool $isDiscovered = false;
    protected static array $permissions = [];
    /** The module the page belongs to, when the school's plan may lack it (ARC-04). */
    protected static ?string $module = null;
    /** Its group in the menu: people and rights, referential, administration. */
    protected static ?string $section = null;
    #[Locked] public string $schoolId;

    public static function canAccess(): bool
    {
        if (static::$module !== null && !DB::selectOne('SELECT school.module_enabled(?) AS enabled', [static::$module])->enabled) return false;
        foreach (static::$permissions as $permission) if (app(Permissions::class)->can($permission)) return true;
        return static::$permissions === [];
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$section === null ? null : __('admin.sections.'.static::$section);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->schoolId = app(CurrentSchool::class)->id();
    }

    public function hydrate(): void
    {
        // A tab opened in school A must be reloaded after the user selects school B.
        abort_unless($this->schoolId === app(CurrentSchool::class)->id() && static::canAccess(), 403);
    }

    /**
     * A table of the API's rows keeps those it read to find an action's record; without this, the
     * render that follows the action would show them as they were before it.
     */
    public function rendering(): void
    {
        if (method_exists($this, 'flushCachedTableRecords')) $this->flushCachedTableRecords();
    }

    protected function writable(string $permission): bool { return SchoolScreen::writable($permission); }
    protected function writableFor(string $permission, string $entity, string $id): bool { return SchoolScreen::writableFor($permission, $entity, $id); }

    /** A page of the list the API gives, with its total (NFR-PERF-04): the screen never loads the whole list. */
    protected function apiPage(string|array $permission, string $controller, string $method, array $data, int $page, int $perPage, array $parameters = []): LengthAwarePaginator
    {
        $result = SchoolScreen::call($permission, $controller, $method, [...$data, 'paginate' => true, 'page' => $page, 'per_page' => $perPage], $parameters);
        return new LengthAwarePaginator(collect($result['data'])->keyBy('id'), $result['meta']['total'], $perPage, $page);
    }

    public function schoolState(): array
    {
        return SchoolScreen::payload(app()->call([app(SchoolController::class), 'show']))['data'];
    }

    protected function reason(): Textarea
    {
        return Textarea::make('reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(1000);
    }
}
