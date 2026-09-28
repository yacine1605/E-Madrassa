<?php
namespace App\Filament\Pages;
class Settings extends \App\Support\SchoolPage {
 public static string $module='settings';
 protected static ?string $slug='settings';
 protected static ?string $navigationLabel='Paramètres';
 protected static string|\UnitEnum|null $navigationGroup='Administration';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-cog-6-tooth';
 protected static ?int $navigationSort=28;
}
