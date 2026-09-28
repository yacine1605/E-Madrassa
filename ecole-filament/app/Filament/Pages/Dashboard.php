<?php
namespace App\Filament\Pages;
class Dashboard extends \App\Support\SchoolPage {
 public static string $module='dashboard';
 protected static ?string $slug='dashboard';
 protected static ?string $navigationLabel='Tableau de bord';
 protected static string|\UnitEnum|null $navigationGroup='Pilotage';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-squares-2x2';
 protected static ?int $navigationSort=0;
}
