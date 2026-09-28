<?php
namespace App\Filament\Pages;
class Analytics extends \App\Support\SchoolPage {
 public static string $module='analytics';
 protected static ?string $slug='analytics';
 protected static ?string $navigationLabel='Analyses et alertes';
 protected static string|\UnitEnum|null $navigationGroup='Intelligence pédagogique';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-presentation-chart-line';
 protected static ?int $navigationSort=23;
}
