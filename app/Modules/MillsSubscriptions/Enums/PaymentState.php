<?php

namespace App\Modules\MillsSubscriptions\Enums;

/**
 * PaymentState — per-subscription billability (ARCHITECTURE.md §3). Independent
 * of SubscriptionStatus: a subscription can be `active` yet `needs_card_update`
 * (the iCount wall). Billing only ever charges `payme` subscriptions.
 *
 * Nothing but `payme` is billable, and that is the whole safety of adding a state: the
 * dispatcher selects `payme` by name and the orchestrator refuses everything else, so a
 * new value is unbillable before any code learns what it means.
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

    /**
     * Entered by an admin WITHOUT a card, on purpose. A regular subscriber — dogs,
     * products, a billing day — whose cycles move forward on schedule and are never
     * charged: no ledger row, no Shopify order, no document, no receipt
     * (NoChargeCycleAdvancer).
     *
     * Not the iCount wall. Nobody is waiting for a card, so the personal area shows an
     * ordinary subscription (`integration_source: payme`, `requires_card_update: false`)
     * and never asks for one. A card saved later does NOT start billing either — only an
     * admin switching this back to `payme` does, because a subscription that quietly began
     * charging the moment someone typed in a card is a surprise charge.
     */
    case NO_CHARGE = 'no_charge';

    public function isBillable(): bool
    {
        return $this === self::PAYME;
    }

    public function requiresCardUpdate(): bool
    {
        return $this === self::NEEDS_CARD_UPDATE;
    }

    /** Cycles move forward on schedule, and nothing is ever charged. */
    public function isNoCharge(): bool
    {
        return $this === self::NO_CHARGE;
    }
}
