<?php
namespace App\Filament\Pages;
class Teachers extends \App\Support\SchoolPage {
 public static string $module='teachers';
 protected static ?string $slug='teachers';
 protected static ?string $navigationLabel='Enseignants';
 protected static string|\UnitEnum|null $navigationGroup='Équipe';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-users';
 protected static ?int $navigationSort=14;
}
