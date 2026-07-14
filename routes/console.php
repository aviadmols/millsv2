<?php

use Illuminate\Support\Facades\Schedule;

/*
 * Scheduled work runs on the dedicated Railway `scheduler` service
 * (php artisan schedule:work) — never a backgrounded child of the web process
 * (the v1 failure). ARCHITECTURE.md §5/§8.
 */

// Liveness beacon for the observability page.
Schedule::command('mills:heartbeat')->everyMinute();

// Recurring-charge dispatcher — window select with automatic catch-up.
Schedule::command('mills:dispatch-due')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Resolve charges whose answer PayMe never gave us.
 *
 * A pending ledger row BLOCKS its subscription from being charged again — deliberately,
 * because the card may already have been debited. This is the only thing that unblocks it,
 * so it must run as reliably as the dispatcher itself: money sits in limbo until it does.
 */
Schedule::command('mills:reconcile-payments')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

/*
 * Recover cards PayMe captured but never handed back.
 *
 * The token only reaches us if the customer's browser returns to our callback. Close the tab
 * and the card is tokenised, the verification charge is taken, and we hold nothing — the
 * customer stays unbillable and nobody ever finds out. This is the only thing that looks.
 */
Schedule::command('mills:reconcile-card-updates')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Log retention — delete system_logs / cron_runs older than
// config('mills.logging.retention_days') (default 60 days).
Schedule::command('logs:prune')
    ->dailyAt('03:20')
    ->withoutOverlapping()
    ->onOneServer();
