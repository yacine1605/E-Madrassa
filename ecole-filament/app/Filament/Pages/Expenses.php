<?php
namespace App\Filament\Pages;
class Expenses extends \App\Support\SchoolPage {
 public static string $module='expenses';
 protected static ?string $slug='expenses';
 protected static ?string $navigationLabel='Dépenses';
 protected static string|\UnitEnum|null $navigationGroup='Finances';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-arrow-trending-down';
 protected static ?int $navigationSort=18;
}
