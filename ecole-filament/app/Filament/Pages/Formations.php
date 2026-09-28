<?php
namespace App\Filament\Pages;
class Formations extends \App\Support\SchoolPage {
 public static string $module='formations';
 protected static ?string $slug='formations';
 protected static ?string $navigationLabel='Formations';
 protected static string|\UnitEnum|null $navigationGroup='Scolarité';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-book-open';
 protected static ?int $navigationSort=5;
}
