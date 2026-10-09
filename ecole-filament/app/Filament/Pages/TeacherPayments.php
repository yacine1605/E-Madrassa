<?php
namespace App\Filament\Pages;
class TeacherPayments extends \Filament\Pages\Page {
    use \App\Support\Concerns\HasModuleAccess;
    public static string $module = 'teacher-payments';
    protected string $view = 'filament.pages.teacher-payments';
    protected static ?string $slug = 'teacher-payments';
    protected static ?string $navigationLabel = 'Paiement enseignants';
    protected static string|\UnitEnum|null $navigationGroup = 'Finances';
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 20;
    public function getTitle(): string { return 'Paiement des enseignants'; }
}
