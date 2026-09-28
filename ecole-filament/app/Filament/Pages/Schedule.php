<?php
namespace App\Filament\Pages;
class Schedule extends \App\Support\SchoolPage {
 public static string $module='schedule';
 protected static ?string $slug='schedule';
 protected static ?string $navigationLabel='Emploi du temps';
 protected static string|\UnitEnum|null $navigationGroup='Organisation';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-calendar-days';
 protected static ?int $navigationSort=12;
}
