<?php
namespace App\Providers;
class AppServiceProvider extends \Illuminate\Support\ServiceProvider {
 public function boot():void { \Filament\Support\Facades\FilamentAsset::register([\Filament\Support\Assets\Css::make('school',public_path('css/school.css'))]); }
}
