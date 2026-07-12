<?php

use App\Http\Controllers\Api\CronApiController;
use App\Http\Controllers\Api\CustomerWebhookController;
use App\Http\Controllers\Api\DogApiController;
use App\Http\Controllers\Api\OrderApiController;
use App\Http\Controllers\Api\SubscriptionApiController;
use Illuminate\Support\Facades\Route;

/*
 * The frozen /api contract (SYSTEM-MAP §3.1).
 *
 * Webhooks are HMAC-verified (they come from Shopify, not from us) and therefore
 * sit OUTSIDE the api.secret group. Everything else requires the API secret.
 */

// --- Shopify webhooks (HMAC, no API secret) ---
Route::middleware('shopify.webhook')->group(function () {
    Route::post('orders/webhook/order-paid', [OrderApiController::class, 'webhookOrderPaid']);
    Route::post('customers/webhook/created', [CustomerWebhookController::class, 'created']);
    Route::post('customers/webhook/updated', [CustomerWebhookController::class, 'updated']);
    Route::post('customers/webhook/deleted', [CustomerWebhookController::class, 'deleted']);
});

/*
 * Ids in the PATH may be the numeric id OR a full Shopify GID. A GID contains
 * slashes (gid://shopify/Metaobject/123), which Laravel would otherwise refuse to
 * match into a single {id} segment — and the theme does put the id it got from
 * /me straight into the URL. The pattern ends in digits, so a following segment
 * (…/products) still matches unambiguously.
 */
const ID_PATTERN = '[0-9]+|gid:\/\/shopify\/[A-Za-z]+\/[0-9]+';

// --- Machine-to-machine surface (API secret) ---
Route::middleware('api.secret')
    ->where(['id' => ID_PATTERN, 'customerId' => ID_PATTERN, 'draftOrderId' => ID_PATTERN])
    ->group(function () {
    Route::get('ping', fn () => response()->json(['ok' => true, 'service' => 'mills-v2']))->name('api.ping');

    Route::prefix('subscriptions')->group(function () {
        Route::get('due-today', [SubscriptionApiController::class, 'dueToday']);
        Route::post('from-order', [SubscriptionApiController::class, 'createFromOrder']);

        Route::get('customer/{customerId}', [SubscriptionApiController::class, 'byCustomer']);
        Route::get('status/{status}', [SubscriptionApiController::class, 'byStatus']);
        Route::get('by-draft-order/{draftOrderId}', [SubscriptionApiController::class, 'byDraftOrder']);

        Route::post('{id}/draft-order', [SubscriptionApiController::class, 'createDraftOrder']);
        Route::patch('{id}/draft-order', [SubscriptionApiController::class, 'updateDraftOrder']);
        Route::get('{id}/draft-order', [SubscriptionApiController::class, 'getDraftOrder']);

        Route::get('{id}/products', [SubscriptionApiController::class, 'products']);
        Route::patch('{id}/add-dog', [SubscriptionApiController::class, 'addDog']);
        Route::patch('{id}/remove-dog', [SubscriptionApiController::class, 'removeDog']);

        Route::post('/', [SubscriptionApiController::class, 'store']);
        Route::get('/', [SubscriptionApiController::class, 'index']);
        Route::get('{id}', [SubscriptionApiController::class, 'show']);
        Route::patch('{id}', [SubscriptionApiController::class, 'update']);
        Route::delete('{id}', [SubscriptionApiController::class, 'destroy']);
    });

    Route::prefix('dogs')->group(function () {
        Route::post('quiz', [DogApiController::class, 'saveQuiz']);          // ← the theme's quiz
        Route::post('link-quiz', [DogApiController::class, 'linkQuiz']);
        Route::patch('addons/add', [DogApiController::class, 'addAddon']);
        Route::patch('addons/remove', [DogApiController::class, 'removeAddon']);
        Route::patch('subscription-variant', [DogApiController::class, 'changeSubscriptionVariant']);
        Route::patch('subscription-status', [DogApiController::class, 'changeSubscriptionStatus']);
        Route::post('status', [DogApiController::class, 'changeStatus']);
        Route::post('remove-from-customer', [DogApiController::class, 'removeFromCustomer']);
        Route::patch('update', [DogApiController::class, 'update']);
    });

    Route::prefix('orders')->group(function () {
        Route::post('draft', [OrderApiController::class, 'createDraft']);
        Route::get('process-billing', [OrderApiController::class, 'processBilling']);
    });

    Route::prefix('cron')->group(function () {
        Route::post('init', [CronApiController::class, 'init']);
        Route::post('start', [CronApiController::class, 'start']);
        Route::post('stop', [CronApiController::class, 'stop']);
        Route::post('trigger', [CronApiController::class, 'trigger']);
        Route::get('status', [CronApiController::class, 'status']);
    });
});
