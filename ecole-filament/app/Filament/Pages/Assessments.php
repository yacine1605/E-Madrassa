<?php
namespace App\Filament\Pages;
class Assessments extends \App\Support\SchoolPage {
 public static string $module='assessments';
 protected static ?string $slug='assessments';
 protected static ?string $navigationLabel='Évaluations et devoirs';
 protected static string|\UnitEnum|null $navigationGroup='Pédagogie';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-pencil-square';
 protected static ?int $navigationSort=8;
}
