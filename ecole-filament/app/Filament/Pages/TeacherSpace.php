<?php
namespace App\Filament\Pages;
class TeacherSpace extends \App\Support\SchoolPage {
 public static string $module='teacher-space';
 protected static ?string $slug='teacher-space';
 protected static ?string $navigationLabel='Espace enseignant';
 protected static string|\UnitEnum|null $navigationGroup='Espaces';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-briefcase';
 protected static ?int $navigationSort=24;
}
