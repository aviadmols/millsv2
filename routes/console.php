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
