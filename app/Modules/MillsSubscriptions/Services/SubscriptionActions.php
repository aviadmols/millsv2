<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Domain\Billing\IdempotencyKey;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Carbon\CarbonInterface;
use RuntimeException;
use Throwable;

/**
 * The things an admin can do to a subscription from its screen.
 *
 * Every status change goes through transitionTo() — the guarded state machine — so an
 * illegal move is refused rather than written, and every one of them lands on the
 * customer's timeline. A raw `status = ...` write anywhere in this file would be a bug.
 */
class SubscriptionActions
{
    public function __construct(
        private readonly ChargeOrchestrator $charger,
        private readonly DraftOrderService $drafts,
    ) {}

    /**
     * Stop billing this subscription.
     *
     * `paused` is not merely cosmetic: the dispatcher only ever selects ACTIVE rows, so
     * pausing genuinely removes it from the due query. Nothing else has to be switched
     * off, and nothing else can silently keep charging.
     */
    public function pause(Subscription $subscription, ?string $reason = null): void
    {
        if ($subscription->status === SubscriptionStatus::PAUSED) {
            return;
        }

        $subscription->transitionTo(SubscriptionStatus::PAUSED, array_filter(['reason' => $reason]));

        SystemLog::info('admin', 'subscription paused', array_filter([
            'reason' => $reason,
        ]), ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
    }

    /**
     * Resume billing.
     *
     * A subscription that has been paused past its own charge date would otherwise be
     * billed the instant it resumes — the dispatcher charges anything due "now or
     * earlier", with catch-up. So a next_charge_at left in the past is moved to today:
     * resuming must not be a surprise charge.
     */
    public function resume(Subscription $subscription): void
    {
        if ($subscription->status === SubscriptionStatus::ACTIVE) {
            return;
        }

        $subscription->transitionTo(SubscriptionStatus::ACTIVE);

        if ($subscription->next_charge_at !== null && $subscription->next_charge_at->isPast()) {
            $subscription->forceFill(['next_charge_at' => now()->startOfDay()])->save();
        }

        SystemLog::info('admin', 'subscription resumed', [
            'next_charge_at' => $subscription->next_charge_at?->toDateString(),
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
    }

    /**
     * Skip this cycle: push the next charge forward by one frequency period.
     *
     * The customer is not billed and receives no shipment for this cycle; everything
     * after it shifts with it. Optionally pushed to an explicit date instead.
     */
    public function postponeNextCharge(Subscription $subscription, ?CarbonInterface $until = null): CarbonInterface
    {
        $months = max(1, (int) $subscription->frequency_months);
        $from = $subscription->next_charge_at ?? now();

        $next = $until?->copy()->startOfDay() ?? $from->copy()->addMonthsNoOverflow($months);

        if ($next->isPast()) {
            throw new RuntimeException('postpone_date_in_the_past');
        }

        $subscription->forceFill([
            'next_charge_at' => $next,
            // A postponement is not a failure — clear any retry backoff with it.
            'attempt_count' => 0,
            'next_retry_at' => null,
        ])->save();

        Timeline::record(Timeline::KIND_NOTE, [
            'action' => 'next_charge_postponed',
            'from' => $from->toDateString(),
            'to' => $next->toDateString(),
        ], $subscription->id, $subscription->customer_id);

        SystemLog::info('admin', 'next charge postponed', [
            'from' => $from->toDateString(),
            'to' => $next->toDateString(),
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

        return $next;
    }

    /**
     * Charge this cycle right now instead of waiting for its date.
     *
     * Runs the ordinary pipeline, so all of its protections apply: the ledger row is
     * written before PayMe is called, the idempotency key makes a double charge
     * impossible, and a success creates the Shopify order and rolls the cycle forward.
     *
     * The key is scoped to (subscription, admin, day) — the same admin double-clicking,
     * or clicking again an hour later, cannot take the money twice.
     *
     * @return array{success: bool, status: string, ledger_id?: int, error?: string}
     */
    public function chargeNow(Subscription $subscription, int $adminId): array
    {
        $key = IdempotencyKey::manual($subscription->id, $adminId, now()->toDateString());

        SystemLog::warning('admin', 'manual charge requested', [
            'admin_id' => $adminId,
            'amount' => $subscription->next_charge_amount,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

        $result = $this->charger->charge($subscription, IdempotencyKey::CONTEXT_MANUAL, $key);

        SystemLog::info('admin', 'manual charge finished', [
            'status' => $result['status'],
            'success' => $result['success'],
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

        return $result;
    }

    /**
     * Build or rebuild the upcoming order. Also refreshes the stored charge amount, so
     * the price the admin sees and the price PayMe will be asked for stay the same.
     *
     * @return array<string, mixed>
     */
    public function refreshUpcomingOrder(Subscription $subscription): array
    {
        try {
            return $this->drafts->ensure($subscription);
        } catch (Throwable $e) {
            SystemLog::error('shopify', 'could not build the upcoming order', [
                'message' => $e->getMessage(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

            throw $e;
        }
    }
}
