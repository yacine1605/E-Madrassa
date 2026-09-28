<?php
namespace App\Filament\Pages;
class Enrollments extends \App\Support\SchoolPage {
 public static string $module='enrollments';
 protected static ?string $slug='enrollments';
 protected static ?string $navigationLabel='Inscriptions';
 protected static string|\UnitEnum|null $navigationGroup='Scolarité';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-clipboard-document-check';
 protected static ?int $navigationSort=3;
}
