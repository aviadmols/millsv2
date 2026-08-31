<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A customer who fixes their card is billed for the NEXT cycle — never for the missed ones.
 *
 * Lifting the wall only flipped `payment_state`, leaving a due date that was months in the
 * past. So the first thing that happened after a customer helpfully entered a new card was
 * a charge for a box they never received — and for the imported iCount book, whose dates
 * are stale by definition, that would have been every single one of them.
 *
 * This is the file to read before touching liftCardUpdateWall: everything here is about
 * money leaving a real person's account for something they did not get.
 */
class NoBackChargeAfterCardUpdateTest extends TestCase
{
    use RefreshDatabase;

    private function blockedSubscription(?string $dueAt, int $frequencyMonths = 1): Subscription
    {
        $customer = Customer::query()->create([
            'email' => uniqid('c', true).'@x.co',
            'shopify_customer_id' => (string) random_int(1000, 999999),
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
            'frequency_months' => $frequencyMonths,
            'next_charge_at' => $dueAt,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription->fresh();
    }

    private function lift(Subscription $subscription): int
    {
        return app(CardUpdateService::class)->liftCardUpdateWall($subscription->customer);
    }

    public function test_a_lapsed_charge_date_is_moved_to_the_next_cycle_that_has_not_happened(): void
    {
        // Card expired in May, fixed in August: they owe September, not May.
        $subscription = $this->blockedSubscription(now()->subMonths(3)->setDay(5)->toDateTimeString());

        $this->lift($subscription);

        $next = $subscription->fresh()->next_charge_at;

        $this->assertTrue($next->isFuture(), 'the customer must never be billed for a cycle they already missed');
        $this->assertSame(5, $next->day, 'whole cycles only — the customer keeps their billing day');
    }

    public function test_the_wall_is_still_lifted(): void
    {
        $subscription = $this->blockedSubscription(now()->subMonths(3)->toDateTimeString());

        $this->assertSame(1, $this->lift($subscription));
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);
    }

    public function test_a_two_month_plan_moves_in_two_month_steps(): void
    {
        $subscription = $this->blockedSubscription(now()->subMonths(5)->setDay(12)->toDateTimeString(), 2);

        $this->lift($subscription);
        $next = $subscription->fresh()->next_charge_at;

        $this->assertTrue($next->isFuture());
        $this->assertSame(12, $next->day);
        // An odd number of months would mean the cadence was broken to catch up.
        $this->assertSame(0, (int) $subscription->next_charge_at->diffInMonths($next) % 2);
    }

    public function test_a_date_due_today_is_left_alone(): void
    {
        /*
         * Today is not a missed cycle. Pushing it a month out would quietly deny the store
         * a charge it is owed — the mirror-image mistake of billing for the past.
         */
        $subscription = $this->blockedSubscription(now()->startOfDay()->toDateTimeString());

        $this->lift($subscription);

        $this->assertTrue($subscription->fresh()->next_charge_at->isToday());
    }

    public function test_a_future_date_is_left_alone(): void
    {
        $due = now()->addDays(9)->startOfDay();
        $subscription = $this->blockedSubscription($due->toDateTimeString());

        $this->lift($subscription);

        $this->assertSame($due->toDateString(), $subscription->fresh()->next_charge_at->toDateString());
        $this->assertSame(0, ActivityEvent::query()->where('kind', Timeline::KIND_PLAN_UPDATED)->count());
    }

    public function test_a_subscription_with_no_schedule_survives_the_lift(): void
    {
        $subscription = $this->blockedSubscription(null);

        $this->assertSame(1, $this->lift($subscription));
        $this->assertNull($subscription->fresh()->next_charge_at);
    }

    public function test_the_move_is_recorded_with_both_dates_and_the_reason(): void
    {
        // A charge date that changed by itself is alarming until the row says why.
        $subscription = $this->blockedSubscription(now()->subMonths(2)->toDateTimeString());

        $this->lift($subscription);

        $event = ActivityEvent::query()->where('kind', Timeline::KIND_PLAN_UPDATED)->firstOrFail();

        $this->assertNotSame($event->details['charge_date_from'], $event->details['charge_date_to']);
        $this->assertSame(__('activity.reason_missed_cycles_skipped'), $event->details['reason']);
    }

    public function test_it_no_longer_counts_as_too_far_behind_to_charge(): void
    {
        /*
         * The guard that stops runaway catch-up billing also stopped these customers being
         * charged AT ALL: an imported subscription months overdue was permanently frozen,
         * waiting for a person to notice. Moving the date forward is what actually returns
         * them to normal billing.
         */
        $subscription = $this->blockedSubscription(now()->subMonths(4)->toDateTimeString());

        $this->assertTrue($subscription->isTooFarBehindToCharge());

        $this->lift($subscription);

        $this->assertFalse($subscription->fresh()->isTooFarBehindToCharge());
    }
}
