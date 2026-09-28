<?php
namespace App\Filament\Pages;
class Staff extends \App\Support\SchoolPage {
 public static string $module='staff';
 protected static ?string $slug='staff';
 protected static ?string $navigationLabel='Personnel';
 protected static string|\UnitEnum|null $navigationGroup='Équipe';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-identification';
 protected static ?int $navigationSort=15;
}
