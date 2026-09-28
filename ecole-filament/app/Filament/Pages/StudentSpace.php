<?php
namespace App\Filament\Pages;
class StudentSpace extends \App\Support\SchoolPage {
 public static string $module='student-space';
 protected static ?string $slug='student-space';
 protected static ?string $navigationLabel='Espace élève';
 protected static string|\UnitEnum|null $navigationGroup='Espaces de démonstration';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-face-smile';
 protected static ?int $navigationSort=25;
}
