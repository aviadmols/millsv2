<?php

use App\Http\Controllers\Storefront\OtpAuthController;
use App\Http\Controllers\Storefront\StorefrontAddressController;
use App\Http\Controllers\Storefront\StorefrontDogController;
use App\Http\Controllers\Storefront\StorefrontMeController;
use App\Http\Controllers\Storefront\StorefrontPaymentController;
use App\Http\Controllers\Storefront\StorefrontSubscriptionController;
use Illuminate\Support\Facades\Route;

/*
 * Personal area (SYSTEM-MAP §3.3) — the surface the Shopify theme's customer
 * account area calls. Mounted at /storefront with name prefix "storefront." in
 * bootstrap/app.php. OTP endpoints are public; everything else is gated by the
 * frozen storefront token and throttled per customer (60/min).
 *
 * Every path, verb, body field (incl. the legacy aliases dogIds / variantIds /
 * id) and response envelope matches v1 exactly, so the theme needs no changes
 * beyond pointing its base URL at this app.
 */

// OTP login (ARCHITECTURE.md §6)
Route::post('auth/otp/request', [OtpAuthController::class, 'request'])
    ->middleware('throttle:10,1')->name('auth.otp.request');
Route::post('auth/otp/verify', [OtpAuthController::class, 'verify'])
    ->middleware('throttle:10,1')->name('auth.otp.verify');

// The id the theme sends back is whatever /me gave it — which for imported records
// is a Shopify GID (gid://shopify/Metaobject/123). GIDs contain slashes, so the
// path params must accept them as well as plain numeric ids.
$idPattern = '[0-9]+|gid:\/\/shopify\/[A-Za-z]+\/[0-9]+';

// Token-gated personal area
Route::middleware(['storefront.token', 'throttle:60,1'])
    ->where(['id' => $idPattern])
    ->group(function () {
    Route::get('me', [StorefrontMeController::class, 'show'])->name('me.show');

    // Subscription writes — all blocked by the card-update wall.
    Route::patch('me/subscription/{id}', [StorefrontSubscriptionController::class, 'update'])
        ->name('me.subscription.update');
    Route::patch('me/subscription/{id}/add-dog', [StorefrontSubscriptionController::class, 'addDog'])
        ->name('me.subscription.add-dog');
    Route::patch('me/subscription/{id}/remove-dog', [StorefrontSubscriptionController::class, 'removeDog'])
        ->name('me.subscription.remove-dog');

    // Dog writes — only the billing-affecting ones hit the wall.
    Route::patch('me/dogs/{id}', [StorefrontDogController::class, 'update'])
        ->name('me.dogs.update');
    Route::patch('me/dogs/{id}/change-variant', [StorefrontDogController::class, 'changeVariant'])
        ->name('me.dogs.change-variant');
    Route::patch('me/dogs/{id}/addons/add', [StorefrontDogController::class, 'addAddon'])
        ->name('me.dogs.addons.add');
    Route::patch('me/dogs/{id}/addons/remove', [StorefrontDogController::class, 'removeAddon'])
        ->name('me.dogs.addons.remove');
    Route::post('me/dogs/{id}/remove', [StorefrontDogController::class, 'destroy'])
        ->name('me.dogs.destroy');

    // Quiz → dog
    Route::post('me/quiz-dogs', [StorefrontDogController::class, 'saveQuiz'])
        ->name('me.quiz-dogs.save');
    Route::post('me/quiz-dogs/{quizDogId}/link', [StorefrontDogController::class, 'linkQuiz'])
        ->name('me.quiz-dogs.link');

    // Card update (PayMe hosted page)
    Route::post('me/payment-method/payme/session', [StorefrontPaymentController::class, 'createSession'])
        ->name('me.payment-method.payme.session');

    // Address (local DB first, pushed to Shopify best-effort)
    Route::patch('me/address', [StorefrontAddressController::class, 'update'])
        ->name('me.address.update');
});
