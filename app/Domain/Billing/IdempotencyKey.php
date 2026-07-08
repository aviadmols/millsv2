<?php

namespace App\Domain\Billing;

/**
 * Deterministic idempotency keys (ARCHITECTURE.md §4). The system must NEVER send
 * a second PayMe charge if a `succeeded` payment_ledger row already exists for the
 * same key. Guards against double-clicks, webhook/worker retries, scheduler
 * overlap, and manual admin retries. Single-tenant — no shop id in the key.
 */
final class IdempotencyKey
{
    // === CONSTANTS ===
    public const CONTEXT_RECURRING = 'recurring';

    public const CONTEXT_RETRY = 'retry';

    public const CONTEXT_MANUAL = 'manual';

    /** The daily recurring charge for a subscription on a given cycle date (Y-m-d, UTC). */
    public static function recurring(int $subscriptionId, string $cycleDate): string
    {
        return self::CONTEXT_RECURRING.":{$subscriptionId}:{$cycleDate}";
    }

    /** A domain-scheduled retry of a specific ledger row. */
    public static function retry(int $ledgerId, int $attemptNumber): string
    {
        return self::CONTEXT_RETRY.":{$ledgerId}:{$attemptNumber}";
    }

    /** An admin "charge now" action (one per admin per subscription per day). */
    public static function manual(int $subscriptionId, int $adminId, string $date): string
    {
        return self::CONTEXT_MANUAL.":{$subscriptionId}:{$adminId}:{$date}";
    }
}
