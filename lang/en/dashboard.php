<?php

return [
    'customers' => 'Customers',
    'active_subscriptions' => 'Active subscriptions',
    'charges_30d' => 'Successful charges (30 days)',

    // KPI cards
    'processed_revenue' => 'Processed revenue (30 days)',
    'charges_count' => ':count charges',
    'active_subscribers' => 'Active subscribers',
    'paused_count' => ':count paused',
    'new_subscribers' => 'New subscribers (30 days)',
    'churned_subscribers' => 'Churned (30 days)',
    'failed_charges' => ':count charges failed',
    'vs_previous' => 'vs the previous period',

    // Upcoming
    'upcoming_heading' => 'Upcoming charges',
    'overdue' => 'Overdue',
    'due_today' => 'Today',
    'next_7_days' => 'Next 7 days',
    'next_30_days' => 'Next 30 days',
    'charges_pending' => ':count charges',
    'blocked_card' => ':count blocked (card needed)',
    'blocked_amount' => ':count with no amount',

    // Upcoming orders table
    'upcoming_orders' => 'Upcoming orders',
    'charge_date' => 'Charge date',
    'amount' => 'Amount',
    'amount_missing' => 'Unknown',
    'total' => 'Total',
    'open' => 'Open',
    'overdue_by' => ':days days overdue',
    'no_upcoming' => 'No upcoming charges',
    'no_upcoming_help' => 'A subscription appears here once it is active, has a payment method, and has a known amount.',

    // System status
    'health_heading' => 'System status',
    'health_description' => 'What is actually running — not what is supposed to be.',
    'health_all_ok' => 'All good',
    'health_attention' => 'Needs attention',
    'health_configured' => 'Configured',
    'health_not_configured' => 'Not configured',

    'health_billing' => 'Recurring billing (CRON)',
    'health_billing_ran' => 'Ran :when',
    'health_billing_at' => 'Last run: :time',
    'health_billing_never' => 'Has never run',
    'health_billing_never_help' => 'The scheduler is not running. Create a Railway service with PROCESS=scheduler — without it, nobody is ever charged.',
    'health_billing_off' => 'Billing is switched off',

    'health_payments' => 'Stuck charges',
    'health_payments_ok' => 'No charges awaiting an answer',
    'health_payments_stuck' => ':count charges with no answer from PayMe',
    'health_payments_stuck_help' => 'Money in an unknown state — the subscription is blocked from being charged until it is resolved. Run mills:reconcile-payments.',

    'health_shopify' => 'Shopify connection',
    'health_shopify_off' => 'Not connected',
    'health_shopify_off_help' => 'Settings → "Reconnect Shopify". Without it there is no product sync and no order creation.',

    'health_payme' => 'Payments (PayMe)',
    'health_payme_help' => 'Settings → PayMe. Without it no money can be collected.',

    'health_sms' => 'SMS (019)',
    'health_sms_help' => 'Settings → SMS. Without it no login code can be sent.',

    'health_recent_runs' => 'Recent runs',
    'health_no_runs' => 'No run has ever been recorded — the scheduler is not running.',
];
