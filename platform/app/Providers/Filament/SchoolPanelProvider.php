<?php

namespace App\Providers\Filament;

use App\Filament\School\Pages\Activation;
use App\Filament\School\Pages\Recovery;
use App\Filament\School\Pages\SchoolLogin;
use App\Http\Middleware\AccountState;
use App\Http\Middleware\Localize;
use App\Http\Middleware\SchoolPanelContext;
use App\Http\Middleware\RequireModule;
use App\Http\Middleware\RequirePermission;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

final class SchoolPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->id('school')->path('school')->login(SchoolLogin::class)
            ->brandName(fn () => __('school.brand'))->colors(['primary' => Color::Indigo])
            ->homeUrl('/school/home')
            ->discoverPages(in: app_path('Filament/School/Pages'), for: 'App\\Filament\\School\\Pages')
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, Localize::class,
                ShareErrorsFromSession::class, ValidateCsrfToken::class, SubstituteBindings::class,
                DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
            ->authMiddleware([Authenticate::class, AccountState::class, SchoolPanelContext::class], isPersistent: true)
            ->persistentMiddleware([RequireModule::class, RequirePermission::class])
            // Before sign-in: the pages activation and recovery links open (FR-IAM-01, FR-IAM-02).
            ->routes(function () {
                Route::get('activation', Activation::class)->name('activation');
                Route::get('recovery', Recovery::class)->name('recovery');
            })
            ->userMenuItems([
                Action::make('choose-school')->label(fn () => __('school.choose_school'))->url('/school/choose-school')->icon('heroicon-o-building-office'),
                Action::make('credentials')->label(fn () => __('school.credentials'))->url('/school/credentials')->icon('heroicon-o-lock-closed'),
            ]);
    }
}
