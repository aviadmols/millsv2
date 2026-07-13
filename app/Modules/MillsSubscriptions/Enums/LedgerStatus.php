<?php

namespace App\Modules\MillsSubscriptions\Enums;

/**
 * PaymentLedgerStatus — the money truth (ARCHITECTURE.md §3). A ledger row opens
 * `pending` BEFORE the PayMe call, then transitions toward a terminal/retry state.
 *
 *   pending         → succeeded · failed
 *   failed          → retry_scheduled
 *   retry_scheduled → pending (the retry re-leases the charge) · succeeded · failed
 *   succeeded       → refunded
 *
 * `pending` is not merely "started" — it is the LEASE. While a row is pending we do not
 * know whether the card was debited (the answer may simply have been lost), so no second
 * charge may be sent for that key until reconciliation resolves it. That is why a retry
 * must pass back through `pending` rather than firing straight at the gateway.
 */
enum LedgerStatus: string
{
    case PENDING = 'pending';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
    case RETRY_SCHEDULED = 'retry_scheduled';
    case REFUNDED = 'refunded';

    /** @return array<string, list<self>> */
    public static function allowed(): array
    {
        return [
            self::PENDING->value => [self::SUCCEEDED, self::FAILED],
            self::FAILED->value => [self::RETRY_SCHEDULED],
            self::RETRY_SCHEDULED->value => [self::PENDING, self::SUCCEEDED, self::FAILED],
            self::SUCCEEDED->value => [self::REFUNDED],
            self::REFUNDED->value => [],
        ];
    }

    public function isTerminal(): bool
    {
        return $this === self::REFUNDED;
    }

    /** While pending, the outcome is unknown and the charge must not be repeated. */
    public function blocksRecharge(): bool
    {
        return $this === self::PENDING || $this === self::SUCCEEDED;
    }
}
