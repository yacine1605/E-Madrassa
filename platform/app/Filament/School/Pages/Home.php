<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\SchoolController;
use App\Support\SchoolScreen;

final class Home extends SchoolPage
{
    protected static bool $isDiscovered = true;
    protected static ?int $navigationSort = 0;
    protected string $view = 'filament.school.home';

    public static function getNavigationLabel(): string { return __('school.home'); }
    public function getTitle(): string { return __('school.home'); }

    public function school(): array
    {
        return SchoolScreen::payload(app()->call([app(SchoolController::class), 'show']))['data'];
    }
}
