<?php
namespace App\Filament\Pages;
class Imports extends \App\Support\SchoolPage {
 public static string $module='imports';
 protected static ?string $slug='imports';
 protected static ?string $navigationLabel='Import de données';
 protected static string|\UnitEnum|null $navigationGroup='Administration';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-arrow-up-tray';
 protected static ?int $navigationSort=26;
}
