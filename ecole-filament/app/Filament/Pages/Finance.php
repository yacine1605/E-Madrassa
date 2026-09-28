<?php
namespace App\Filament\Pages;
class Finance extends \App\Support\SchoolPage {
 public static string $module='finance';
 protected static ?string $slug='finance';
 protected static ?string $navigationLabel='Synthèse financière';
 protected static string|\UnitEnum|null $navigationGroup='Finances';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-chart-bar';
 protected static ?int $navigationSort=20;
}
