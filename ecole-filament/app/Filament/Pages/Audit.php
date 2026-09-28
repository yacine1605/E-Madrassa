<?php
namespace App\Filament\Pages;
class Audit extends \App\Support\SchoolPage {
 public static string $module='audit';
 protected static ?string $slug='audit';
 protected static ?string $navigationLabel='Journal des modifications';
 protected static string|\UnitEnum|null $navigationGroup='Administration';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-queue-list';
 protected static ?int $navigationSort=29;
}
