<?php

return [
    // Domain-scheduled retry backoff after a failed charge (ARCHITECTURE.md §5).
    'retry_backoff_hours' => [4, 24, 72],

    'currency' => env('BILLING_CURRENCY', 'ILS'),

    // The scheduler selects subscriptions with next_charge_at <= now + this window
    // (minutes). 0 = strictly due. A small window smooths clock skew.
    'dispatch_window_minutes' => (int) env('BILLING_DISPATCH_WINDOW_MINUTES', 0),

    // The ONLY off switch for billing (CLAUDE.md law #10). No cache toggle.
    'kill_switch' => filter_var(env('BILLING_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),
];
