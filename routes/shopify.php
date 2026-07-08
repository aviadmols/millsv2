<?php

use App\Http\Controllers\Shopify\OAuthController;
use App\Http\Controllers\Shopify\WebhookController;
use Illuminate\Support\Facades\Route;

/*
 * Shopify app surface (ARCHITECTURE.md §1b). Registered outside the web group so
 * the webhook POST is not subject to CSRF. HMAC (query for OAuth, body for
 * webhooks) is the auth.
 */

Route::get('shopify/install', [OAuthController::class, 'install'])->name('shopify.install');
Route::get('shopify/callback', [OAuthController::class, 'callback'])->name('shopify.callback');

Route::post('shopify/webhooks', [WebhookController::class, 'handle'])
    ->middleware('shopify.webhook')
    ->name('shopify.webhooks');
