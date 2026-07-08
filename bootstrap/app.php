<?php

use App\Http\Middleware\ValidateApiSecret;
use App\Http\Middleware\VerifyShopifyWebhook;
use App\Http\Middleware\VerifyStorefrontToken;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Personal area — /storefront/*, name prefix storefront.* (SYSTEM-MAP §3.3)
            Route::middleware('api')
                ->prefix('storefront')
                ->name('storefront.')
                ->group(base_path('routes/storefront.php'));

            // Shopify app surface — OAuth + webhooks (no web session / no CSRF).
            Route::group([], base_path('routes/shopify.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'storefront.token' => VerifyStorefrontToken::class,
            'api.secret' => ValidateApiSecret::class,
            'shopify.webhook' => VerifyShopifyWebhook::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('storefront/*'),
        );
    })->create();
