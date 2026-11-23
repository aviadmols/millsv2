<?php

return [
    // Domain-scheduled retry backoff after a failed charge (ARCHITECTURE.md §5).
    // One attempt a day for five days: the charge itself, then four daily retries; the
    // fifth decline moves the subscription to past_due (Aviad, 2026-11-23).
    'retry_backoff_hours' => [24, 24, 24, 24],

    // However a charge job got queued, an automatic attempt never follows a declined one
    // for the same subscription within this many hours. 20, not 24, so a daily retry that
    // lands a few minutes early is not pushed a whole day.
    'min_hours_between_attempts' => 20,

    'currency' => env('BILLING_CURRENCY', 'ILS'),

    // The scheduler selects subscriptions with next_charge_at <= now + this window
    // (minutes). 0 = strictly due. A small window smooths clock skew.
    'dispatch_window_minutes' => (int) env('BILLING_DISPATCH_WINDOW_MINUTES', 0),

    // The ONLY off switch for billing (CLAUDE.md law #10). No cache toggle.
    'kill_switch' => filter_var(env('BILLING_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),
];
