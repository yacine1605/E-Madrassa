<?php
namespace App\Filament\Pages;
class Admissions extends \App\Support\SchoolPage {
 public function getView():string {return config('school.backend')?'filament.pages.admissions':parent::getView();}
 public static string $module='admissions';
 protected static ?string $slug='admissions';
 protected static ?string $navigationLabel='Admissions';
 protected static string|\UnitEnum|null $navigationGroup='Scolarité';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-user-plus';
 protected static ?int $navigationSort=2;
}
