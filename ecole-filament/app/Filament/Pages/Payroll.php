<?php
namespace App\Filament\Pages;
class Payroll extends \App\Support\SchoolPage {
 public static string $module='payroll';
 protected static ?string $slug='payroll';
 protected static ?string $navigationLabel='Rémunérations';
 protected static string|\UnitEnum|null $navigationGroup='Finances';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-wallet';
 protected static ?int $navigationSort=19;
}
