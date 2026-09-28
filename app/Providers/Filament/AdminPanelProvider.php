<?php

namespace App\Providers\Filament;

use App\Http\Controllers\LicenseReviewController;
use App\Http\Controllers\PublicMediaController;
use App\Http\Controllers\SiteReleasePreviewController;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->authenticatedRoutes(function (Panel $panel): void {
                Route::get('/media/{asset}/preview', [PublicMediaController::class, 'operator'])
                    ->middleware(['can:administer-catalog', ...Dashboard::getRouteMiddleware($panel), 'throttle:240,1'])
                    ->name('media.preview');
                Route::get('/licenses/{license}/preview', LicenseReviewController::class)
                    ->middleware(['can:administer-catalog', ...Dashboard::getRouteMiddleware($panel), 'throttle:60,1'])
                    ->name('licenses.preview');
                Route::get('/site-releases/{release}/preview', SiteReleasePreviewController::class)
                    ->middleware(['can:administer-catalog', ...Dashboard::getRouteMiddleware($panel), 'throttle:60,1'])
                    ->name('site-releases.preview');
            })
            ->login()
            ->brandName('VASEY.AUDIO / Studio')
            ->profile()
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: fn () => app()->isProduction())
            ->colors([
                'primary' => Color::hex('#00B8D9'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,

            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
