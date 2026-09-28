<?php
namespace App\Filament\Pages;
class Payments extends \App\Support\SchoolPage {
 public static string $module='payments';
 protected static ?string $slug='payments';
 protected static ?string $navigationLabel='Paiements';
 protected static string|\UnitEnum|null $navigationGroup='Finances';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-banknotes';
 protected static ?int $navigationSort=17;
}
