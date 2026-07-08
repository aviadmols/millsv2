<?php

namespace App\Modules\MillsSubscriptions\Enums;

/**
 * PaymentLedgerStatus — the money truth (ARCHITECTURE.md §3). A ledger row opens
 * `pending` BEFORE the PayMe call, then transitions exactly once toward a
 * terminal/retry state.
 *
 *   pending → succeeded · pending → failed
 *   failed → retry_scheduled · retry_scheduled → succeeded · retry_scheduled → failed
 *   succeeded → refunded
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
            self::RETRY_SCHEDULED->value => [self::SUCCEEDED, self::FAILED],
            self::SUCCEEDED->value => [self::REFUNDED],
            self::REFUNDED->value => [],
        ];
    }

    public function isTerminal(): bool
    {
        return $this === self::REFUNDED;
    }
}
