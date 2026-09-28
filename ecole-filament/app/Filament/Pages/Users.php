<?php
namespace App\Filament\Pages;
class Users extends \App\Support\SchoolPage {
 public static string $module='users';
 protected static ?string $slug='users';
 protected static ?string $navigationLabel='Comptes et habilitations';
 protected static string|\UnitEnum|null $navigationGroup='Administration';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-lock-closed';
 protected static ?int $navigationSort=27;
}
