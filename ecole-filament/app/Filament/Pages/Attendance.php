<?php
namespace App\Filament\Pages;
class Attendance extends \App\Support\SchoolPage {
 public static string $module='attendance';
 protected static ?string $slug='attendance';
 protected static ?string $navigationLabel='Présences';
 protected static string|\UnitEnum|null $navigationGroup='Pédagogie';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-check-circle';
 protected static ?int $navigationSort=10;
}
