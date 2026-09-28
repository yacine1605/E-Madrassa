<?php
namespace App\Filament\Pages;
class Announcements extends \App\Support\SchoolPage {
 public static string $module='announcements';
 protected static ?string $slug='announcements';
 protected static ?string $navigationLabel='Annonces';
 protected static string|\UnitEnum|null $navigationGroup='Communication';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-megaphone';
 protected static ?int $navigationSort=21;
}
