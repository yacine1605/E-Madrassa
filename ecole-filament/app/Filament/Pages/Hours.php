<?php
namespace App\Filament\Pages;
class Hours extends \App\Support\SchoolPage {
 public static string $module='hours';
 protected static ?string $slug='hours';
 protected static ?string $navigationLabel='Heures assurées';
 protected static string|\UnitEnum|null $navigationGroup='Équipe';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-clock';
 protected static ?int $navigationSort=16;
}
