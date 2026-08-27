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
    'include_blocked' => 'Include those awaiting a card update',
    'scope_billable' => 'Billable (PayMe card on file)',
    'scope_all' => 'All active subscriptions (incl. awaiting a card)',
    'active_subscribers_billable' => 'Billable subscribers',
    'active_subscribers_all' => 'All active subscribers',
    'include_blocked_note' => 'Showing potential: includes :count active subscriptions still waiting on a card update. These amounts will not be collected until the customers enter a card.',
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

    'health_worker' => 'Charge worker (queue)',
    'health_worker_ok' => 'Draining normally (:count waiting)',
    'health_worker_stuck' => ':count jobs have been waiting in the queue for over 10 minutes',
    'health_worker_stuck_help' => 'If the number drops on refresh, the worker is catching up on a backlog and this clears itself. If it does not move, the worker is dead and nobody is charged: check that a PROCESS=worker service exists on Railway, is running, and has restart policy ALWAYS.',
    'health_worker_failed' => ':count charges failed in the last 24 hours',
    'health_worker_failed_help' => 'Charges were attempted and threw. Check the failed_jobs table before they are retried.',
    'health_worker_failed_other' => ':count background jobs failed in the last 24 hours (not charges)',
    'health_worker_failed_other_help' => 'Shopify sync, mail or webhooks — not billing, and no money is stuck. The queue column in failed_jobs says which one threw.',

    'health_behind' => 'Subscriptions held back',
    'health_behind_ok' => 'None — every subscription is on schedule',
    'health_behind_count' => ':count subscriptions are more than a cycle behind',
    'health_behind_help' => 'Not charged on purpose — auto-billing would collect every missed cycle back-to-back. Open each one and move the next charge date forward, or cancel it.',

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
    'health_last_run' => 'Last run',
    'health_no_runs' => 'No run has ever been recorded — the scheduler is not running.',

    'cardcom_heading' => 'Waiting for Cardcom removal',
    'cardcom_description' => 'These customers saved a card and are now billed by us — but their old recurring charge in Cardcom must be removed BY HAND, and until it is they are being billed twice. Confirm each one only after removing it in Cardcom.',
    'cardcom_confirm' => 'Removed from Cardcom',
    'cardcom_confirmed' => 'Recorded. The customer is off the double-billing list.',
];
