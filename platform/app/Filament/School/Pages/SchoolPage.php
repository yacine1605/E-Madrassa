<?php

namespace App\Filament\School\Pages;

use App\Support\CurrentSchool;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use App\Http\Controllers\SchoolController;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

abstract class SchoolPage extends Page
{
    protected static bool $isDiscovered = false;
    protected static array $permissions = [];
    #[Locked] public string $schoolId;

    public static function canAccess(): bool
    {
        foreach (static::$permissions as $permission) if (app(Permissions::class)->can($permission)) return true;
        return static::$permissions === [];
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

    protected function writable(string $permission): bool { return SchoolScreen::writable($permission); }

    public function schoolState(): array
    {
        return SchoolScreen::payload(app()->call([app(SchoolController::class), 'show']))['data'];
    }

    protected function reason(): Textarea
    {
        return Textarea::make('reason')->label(__('school.reason'))->required()->minLength(3)->maxLength(1000);
    }
}
