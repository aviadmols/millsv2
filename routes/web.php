<?php

use App\Http\Controllers\PaymentMethodUpdateController;
use Illuminate\Support\Facades\Route;

// Root goes straight to the admin panel; Filament redirects to /admin/login
// when unauthenticated. No default Laravel splash page.
Route::get('/', fn () => redirect('/admin'));

/*
 * PayMe card-update return page (SYSTEM-MAP §3.4). No login — the single-use
 * session_id minted for the authenticated customer is the credential.
 */
Route::get('storefront/payment-method/payme-callback', [PaymentMethodUpdateController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('storefront.payment-method.payme-callback');
