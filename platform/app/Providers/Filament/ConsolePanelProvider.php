<?php

namespace App\Providers\Filament;

use App\Filament\Auth\PlatformAppAuthentication;
use App\Filament\Console\Pages\ConsoleLogin;
use App\Filament\Console\Pages\Supervision;
use App\Http\Middleware\AccountState;
use App\Http\Middleware\Localize;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The publisher's console (W1.2) at /console: operators only, MFA compulsory (FR-IAM-02 for
 * `super_admin`), the platform's lockout and session limits. Its data come through the
 * `platform_admin` connection, which sees no school data (FR-PLT-04 AC1).
 */
final class ConsolePanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('console')
            ->path('console')
            ->login(ConsoleLogin::class)
            ->brandName(fn () => __('console.brand'))
            ->colors(['primary' => Color::Indigo])
            ->multiFactorAuthentication([PlatformAppAuthentication::make()->recoverable()], isRequired: true)
            ->discoverResources(in: app_path('Filament/Console/Resources'), for: 'App\\Filament\\Console\\Resources')
            ->pages([Supervision::class])
            ->homeUrl(fn () => Supervision::getUrl())
            ->middleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, Localize::class, ShareErrorsFromSession::class,
                ValidateCsrfToken::class, SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class])
            ->authMiddleware([Authenticate::class, AccountState::class])
            // FR-I18-01: the operator's language, each one named in itself; Filament turns right to left in Arabic.
            ->userMenuItems(array_map(fn (string $locale) => Action::make('locale-'.$locale)->label(Locales::NAMES[$locale])->icon('heroicon-o-language')
                ->url(fn () => route('filament.console.locale', ['locale' => $locale]))->postToUrl()
                ->visible(fn () => app()->getLocale() !== $locale), Locales::AVAILABLE))
            ->authenticatedRoutes(function () {
                Route::post('locale/{locale}', function (Request $request, string $locale) {
                    abort_unless(in_array($locale, Locales::AVAILABLE, true), 404);
                    $request->user()->forceFill(['locale' => $locale])->save();
                    $request->session()->forget('locale');
                    return redirect()->back();
                })->name('locale');
            });
    }
}
