<?php
namespace App\Filament\Pages;
class Students extends \App\Support\SchoolPage {
 public static string $module='students';
 protected static ?string $slug='students';
 protected static ?string $navigationLabel='Élèves';
 protected static string|\UnitEnum|null $navigationGroup='Scolarité';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-academic-cap';
 protected static ?int $navigationSort=1;
}
