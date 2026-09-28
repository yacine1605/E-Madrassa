<?php
namespace App\Providers\Filament;
use Filament\Panel;
class AdminPanelProvider extends \Filament\PanelProvider {
 public function panel(Panel $panel):Panel {
 return $panel->default()->id('admin')->path('admin')->login()->brandName('Madrassa')->homeUrl('/admin/dashboard')
 ->defaultAvatarProvider(\App\Support\LocalAvatarProvider::class)
 ->font('Inter',provider: \Filament\FontProviders\LocalFontProvider::class,url: asset('css/font.css'))
 ->colors(['primary'=>\Filament\Support\Colors\Color::Teal])->darkMode(false)->sidebarCollapsibleOnDesktop()->maxContentWidth('full')
 ->discoverPages(in:app_path('Filament/Pages'),for:'App\\Filament\\Pages')
 ->middleware([\Illuminate\Cookie\Middleware\EncryptCookies::class,\Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,\Illuminate\Session\Middleware\StartSession::class,\Illuminate\View\Middleware\ShareErrorsFromSession::class,\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,\Illuminate\Routing\Middleware\SubstituteBindings::class,\Filament\Http\Middleware\DisableBladeIconComponents::class,\Filament\Http\Middleware\DispatchServingFilamentEvent::class])
 ->authMiddleware([\Filament\Http\Middleware\Authenticate::class,\App\Http\Middleware\RedirectTeacher::class]);
 }
}
