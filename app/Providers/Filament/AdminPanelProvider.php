<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\MillsStats;
use App\Http\Middleware\ShopifyEmbedded;
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
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Mills Subscriptions')
            // Served from our own public/ rather than the theme's CDN: the admin must not
            // go blank because someone re-uploads the theme.
            ->brandLogo(asset('images/mills-logo.svg'))
            ->brandLogoHeight('1.75rem')
            ->favicon(asset('images/mills-logo.svg'))
            /*
             * Shopify Admin (Polaris) palette, so the app does not look like a stranger once
             * it is embedded inside the Shopify admin.
             */
            ->colors([
                'primary' => Color::hex('#008060'),   // Polaris green — primary actions
                'success' => Color::hex('#008060'),
                'danger' => Color::hex('#D72C0D'),    // Polaris critical
                'warning' => Color::hex('#B98900'),   // Polaris caution
                'info' => Color::hex('#2C6ECB'),      // Polaris highlight
                'gray' => Color::Slate,
            ])
            // App Bridge — makes the panel run embedded inside Shopify Admin.
            ->renderHook(
                'panels::head.start',
                function (): HtmlString {
                    $key = (string) config('shopify.api_key', '');
                    if (! config('shopify.embedded') || $key === '') {
                        return new HtmlString('');
                    }

                    return new HtmlString(
                        '<meta name="shopify-api-key" content="'.e($key).'">'
                        .'<script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>'
                    );
                },
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                MillsStats::class,
                AccountWidget::class,
            ])
            ->middleware([
                ShopifyEmbedded::class,
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
