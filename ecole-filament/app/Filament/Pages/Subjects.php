<?php
namespace App\Filament\Pages;
class Subjects extends \App\Support\SchoolPage {
 public static string $module='subjects';
 protected static ?string $slug='subjects';
 protected static ?string $navigationLabel='Matières et compétences';
 protected static string|\UnitEnum|null $navigationGroup='Pédagogie';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-bookmark-square';
 protected static ?int $navigationSort=6;
}
