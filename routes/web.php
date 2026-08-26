<?php

use App\Http\Controllers\PaymentMethodUpdateController;
use Illuminate\Support\Facades\Route;

// Root goes straight to the admin panel; Filament redirects to /admin/login
// when unauthenticated. No default Laravel splash page.
Route::get('/', fn () => redirect('/admin'));

/*
 * PayMe card-update pages (SYSTEM-MAP §3.4). No login on any of them — the
 * single-use session_id (15-minute TTL) minted for the authenticated customer
 * is the credential, exactly as in v1.
 *
 * payme-form + payme-token are the Hosted Fields flow (the v1 page, ported):
 * our form, PayMe-hosted card iframes, tokenised in the browser — no
 * verification sale and no charge. payme-callback serves the legacy
 * hosted-page flow and stays for sessions opened before the switch.
 */
Route::get('storefront/payment-method/payme-form', [PaymentMethodUpdateController::class, 'form'])
    ->middleware('throttle:30,1')
    ->name('storefront.payment-method.payme-form');

Route::post('storefront/payment-method/payme-token', [PaymentMethodUpdateController::class, 'storeToken'])
    ->middleware('throttle:30,1')
    ->name('storefront.payment-method.payme-token');

Route::get('storefront/payment-method/payme-callback', [PaymentMethodUpdateController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('storefront.payment-method.payme-callback');
