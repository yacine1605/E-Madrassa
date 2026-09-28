<?php
namespace App\Filament\Pages;
class Lessons extends \App\Support\SchoolPage {
 public static string $module='lessons';
 protected static ?string $slug='lessons';
 protected static ?string $navigationLabel='Leçons';
 protected static string|\UnitEnum|null $navigationGroup='Pédagogie';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-document-text';
 protected static ?int $navigationSort=7;
}
