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

    /** The small verification sale that captures a card. Not a subscription charge. */
    public const CONTEXT_CARD_UPDATE = 'card_update';

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

    /**
     * A card-update verification sale, keyed on the session — NOT on a date.
     *
     * This is the one key that must NOT collapse. Every other key here exists to make a
     * repeat collapse onto the same row, because charging the same cycle twice is the
     * catastrophe. A card update is the opposite: two attempts on the same day are two
     * genuinely different sales at PayMe, and folding them into one row would leave the
     * second card captured with no trace of it.
     */
    public static function cardUpdate(string $sessionId): string
    {
        return self::CONTEXT_CARD_UPDATE.":{$sessionId}";
    }

    /**
     * The contexts the BILLING engine owns.
     *
     * `mills:reconcile-payments` sweeps every pending row and resolves it as a subscription
     * charge — looking it up at PayMe, and on `not_found` marking it failed, bumping the
     * SUBSCRIPTION's attempt count and scheduling a billing backoff. Run that over a
     * card-update row and it schedules retries for a charge that never existed.
     *
     * @return list<string>
     */
    public static function billingContexts(): array
    {
        return [self::CONTEXT_RECURRING, self::CONTEXT_RETRY, self::CONTEXT_MANUAL];
    }
}
