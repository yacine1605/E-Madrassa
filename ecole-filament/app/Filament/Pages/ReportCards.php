<?php
namespace App\Filament\Pages;

class ReportCards extends \Filament\Pages\Page
{
    use \App\Support\Concerns\HasModuleAccess;
    public static string $module = 'report-cards';
    protected string $view = 'filament.pages.report-cards';
    protected static ?string $slug = 'bulletins';
    protected static ?string $navigationLabel = 'Bulletins scolaires';
    protected static string|\UnitEnum|null $navigationGroup = 'Pédagogie';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 10;
    public function getTitle(): string { return 'Bulletins scolaires'; }
}
