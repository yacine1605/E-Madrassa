<?php
namespace App\Filament\Pages;
class Optimization extends \App\Support\SchoolPage {
 public static string $module='optimization';
 protected static ?string $slug='optimization';
 protected static ?string $navigationLabel='Affectations et optimisation';
 protected static string|\UnitEnum|null $navigationGroup='Intelligence pédagogique';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-sparkles';
 protected static ?int $navigationSort=22;
}
