<?php

use Illuminate\Support\Facades\Route;

/*
 * The frozen /api contract (SYSTEM-MAP §3.1), guarded by the API secret. The full
 * surface (subscriptions, dogs, orders/cron, webhooks) is implemented in Phase 4;
 * for now this establishes the guarded group + a health ping.
 */

Route::middleware('api.secret')->group(function () {
    Route::get('ping', fn () => response()->json(['ok' => true, 'service' => 'mills-v2']))->name('api.ping');
    // TODO Phase 4: /subscriptions*, /dogs*, /orders*, /cron* + legacy NestJS paths
});
