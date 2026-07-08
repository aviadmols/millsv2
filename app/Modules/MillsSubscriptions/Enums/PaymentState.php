<?php

namespace App\Modules\MillsSubscriptions\Enums;

/**
 * PaymentState — per-subscription billability (ARCHITECTURE.md §3). Independent
 * of SubscriptionStatus: a subscription can be `active` yet `needs_card_update`
 * (the iCount wall). Billing only ever charges `payme` subscriptions.
 */
enum PaymentState: string
{
    /** A saved PayMe buyer_key is on file — the subscription is billable. */
    case PAYME = 'payme';

    /**
     * Legacy iCount customer (no PayMe card yet). Billing skips it; the personal
     * area shows `requires_card_update:true`; billing-affecting writes are blocked
     * with 403 `icount_requires_card_update` (frozen v1 behaviour).
     */
    case NEEDS_CARD_UPDATE = 'needs_card_update';

    public function isBillable(): bool
    {
        return $this === self::PAYME;
    }

    public function requiresCardUpdate(): bool
    {
        return $this === self::NEEDS_CARD_UPDATE;
    }
}
