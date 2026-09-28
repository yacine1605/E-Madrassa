<?php
namespace App\Filament\Pages;
class Groups extends \App\Support\SchoolPage {
 public static string $module='groups';
 protected static ?string $slug='groups';
 protected static ?string $navigationLabel='Classes et groupes';
 protected static string|\UnitEnum|null $navigationGroup='Scolarité';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-user-group';
 protected static ?int $navigationSort=4;
}
