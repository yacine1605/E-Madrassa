<?php
namespace App\Filament\Pages;
class Interventions extends \App\Support\SchoolPage {
 public static string $module='interventions';
 protected static ?string $slug='interventions';
 protected static ?string $navigationLabel='Suivi pédagogique';
 protected static string|\UnitEnum|null $navigationGroup='Pédagogie';
 protected static string|\BackedEnum|null $navigationIcon='heroicon-o-chat-bubble-left-right';
 protected static ?int $navigationSort=11;
}
