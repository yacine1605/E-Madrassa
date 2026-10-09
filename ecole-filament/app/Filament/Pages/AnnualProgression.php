<?php
namespace App\Filament\Pages;
class AnnualProgression extends \Filament\Pages\Page {
    use \App\Support\Concerns\HasModuleAccess;
    public static string $module = 'annual-progression';
    protected string $view = 'filament.pages.annual-progression';
    protected static ?string $slug = 'annual-progression';
    protected static ?string $navigationLabel = 'Passage annuel';
    protected static string|\UnitEnum|null $navigationGroup = 'Scolarité';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-academic-cap';
    protected static ?int $navigationSort = 8;
    public function getTitle(): string { return 'Passage annuel et réinscriptions'; }
}
