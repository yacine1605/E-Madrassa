<?php
namespace App\Filament\Pages;
class Grades extends \App\Support\SchoolPage {
 public static string $module='grades';
 protected static ?string $slug='grades';
 protected static ?string $navigationLabel='Carnet de notes';
 protected static string|\UnitEnum|null $navigationGroup='Pédagogie';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-table-cells';
 protected static ?int $navigationSort=9;
}
