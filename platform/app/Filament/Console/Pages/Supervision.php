<?php

namespace App\Filament\Console\Pages;

use App\Support\Console;
use Filament\Pages\Page;

/** FR-PLT-05: the schools by their numbers only; nothing a school writes appears here. */
final class Supervision extends Page
{
    protected static ?string $slug = 'supervision';
    protected static ?int $navigationSort = 1;
    protected string $view = 'filament.console.pages.supervision';

    public static function getNavigationLabel(): string { return __('console.supervision.title'); }

    public function getTitle(): string { return __('console.supervision.title'); }

    protected function getViewData(): array
    {
        return ['schools' => app(Console::class)->supervision()];
    }
}
