<?php

use App\Http\Controllers\Storefront\OtpAuthController;
use App\Http\Controllers\Storefront\StorefrontMeController;
use Illuminate\Support\Facades\Route;

/*
 * Personal area (SYSTEM-MAP §3.3). Mounted at /storefront with name prefix
 * "storefront." in bootstrap/app.php. OTP endpoints are public; everything else
 * is gated by the frozen storefront token.
 */

// OTP login (ARCHITECTURE.md §6)
Route::post('auth/otp/request', [OtpAuthController::class, 'request'])
    ->middleware('throttle:10,1')->name('auth.otp.request');
Route::post('auth/otp/verify', [OtpAuthController::class, 'verify'])
    ->middleware('throttle:10,1')->name('auth.otp.verify');

// Token-gated personal area
Route::middleware(['storefront.token', 'throttle:60,1'])->group(function () {
    Route::get('me', [StorefrontMeController::class, 'show'])->name('me.show');
    // TODO Phase 4: subscription/dog writes, card-update session, address, quiz-dogs
});
