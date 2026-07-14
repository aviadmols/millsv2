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
use Filament\Support\Enums\Width;
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
             * Use the whole window.
             *
             * Filament caps page content at 7xl (1280px). On a wide monitor that leaves the
             * subscription screen sitting in the left 45% with a field of empty grey beside
             * it — and this is a screen built to show a two-column layout, an order, a product
             * list and an order history at once. It needs the width it was designed for.
             */
            ->maxContentWidth(Width::Full)
            /*
             * The standard SaaS-admin palette (Klaviyo, Stripe, Linear): one indigo accent
             * over neutral slate, with colour reserved for MEANING — green is "it worked",
             * red is "it failed". The old Polaris green doubled as both the brand colour and
             * the success colour, so a plain "Save" button looked exactly like a confirmation.
             */
            ->colors([
                'primary' => Color::hex('#4F46E5'),   // indigo — actions
                'success' => Color::hex('#16A34A'),
                'danger' => Color::hex('#DC2626'),
                'warning' => Color::hex('#D97706'),
                'info' => Color::hex('#2563EB'),
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
