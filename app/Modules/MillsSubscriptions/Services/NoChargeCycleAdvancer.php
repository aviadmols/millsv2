<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Moves a NO-CHARGE subscription on to its next cycle — and does nothing else.
 *
 * A `no_charge` subscription is a real subscriber with dogs, products and a billing day,
 * entered by an admin without a card. It keeps its rhythm, so the date moves forward
 * exactly as a paying subscription's does. What it never does is touch money: no charge,
 * no ledger row, no Shopify order, no document, no receipt. There is nothing here that
 * could — this class writes one column and one timeline row.
 *
 * The dates match a paying subscription's to the day. A charge advances the date by
 * `frequency_months` from the date that was due (ChargeOrchestrator::onSuccess), and this
 * steps the same way, so a subscription moved between the two modes never finds itself on
 * a different day of the month than it would have been.
 *
 * Unlike a charge, it does not stop at one cycle. A paying subscription more than a cycle
 * behind is held for a person, because each extra step would be another month billed.
 * Here a step costs nothing, so a date left months in the past simply lands on the first
 * cycle still ahead — in one move, and one timeline row saying from where to where.
 */
final class NoChargeCycleAdvancer
{
    // === CONSTANTS ===
    /** A date corrupted to the distant past must not spin here forever. 50 years of monthly cycles. */
    public const MAX_STEPS = 600;

    /** Subscriptions handled per query page. */
    public const CHUNK = 100;

    /**
     * Move every due no-charge subscription to its next cycle.
     *
     * @return int how many subscriptions moved
     */
    public function advanceDue(CarbonInterface $cutoff): int
    {
        $moved = 0;

        Subscription::query()
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('payment_state', PaymentState::NO_CHARGE->value)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($subscriptions) use (&$moved): void {
                foreach ($subscriptions as $subscription) {
                    if ($this->advance((int) $subscription->id)) {
                        $moved++;
                    }
                }
            });

        return $moved;
    }

    /**
     * One subscription, under its row lock, re-checked against what is true NOW — an admin
     * may have switched it to PayMe, paused it or moved its date since the page was read.
     */
    private function advance(int $subscriptionId): bool
    {
        return DB::transaction(function () use ($subscriptionId): bool {
            $subscription = Subscription::query()->lockForUpdate()->find($subscriptionId);

            if ($subscription === null
                || $subscription->status !== SubscriptionStatus::ACTIVE
                || $subscription->payment_state !== PaymentState::NO_CHARGE
                || $subscription->next_charge_at === null
                || $subscription->next_charge_at->isFuture()) {
                return false;
            }

            $from = $subscription->next_charge_at->copy();
            $to = $this->nextCycleAfterToday($from, max(1, (int) $subscription->frequency_months));

            $subscription->forceFill(['next_charge_at' => $to])->save();

            Timeline::record(
                Timeline::KIND_PLAN_UPDATED,
                [
                    'charge_date_from' => $from->toDateString(),
                    'charge_date_to' => $to->toDateString(),
                    'reason' => __('activity.reason_no_charge_cycle'),
                ],
                $subscription->id,
                $subscription->customer_id,
            );

            SystemLog::info('billing', 'no-charge subscription moved to its next cycle', [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

            return true;
        });
    }

    /**
     * Whole cycles forward until the date is after today — the same step a charge takes.
     *
     * "After today", not "after now": next_charge_at is a date, and a subscription due today
     * that is moved to today has not moved.
     */
    private function nextCycleAfterToday(CarbonInterface $from, int $months): CarbonInterface
    {
        $next = $from->copy();
        $today = now()->startOfDay();

        for ($step = 0; $step < self::MAX_STEPS && $next->copy()->startOfDay()->lte($today); $step++) {
            $next = $next->addMonthsNoOverflow($months);
        }

        return $next;
    }
}
