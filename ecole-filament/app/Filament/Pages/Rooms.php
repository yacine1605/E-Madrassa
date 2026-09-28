<?php
namespace App\Filament\Pages;
class Rooms extends \App\Support\SchoolPage {
 public static string $module='rooms';
 protected static ?string $slug='rooms';
 protected static ?string $navigationLabel='Salles';
 protected static string|\UnitEnum|null $navigationGroup='Organisation';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-building-office-2';
 protected static ?int $navigationSort=13;
}
