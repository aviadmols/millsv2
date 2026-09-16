<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\ActivityEvent;
use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use App\Modules\MillsSubscriptions\Services\ChargeOrchestrator;
use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A subscriber entered without a card: the cycles move, and money never does.
 *
 * Everything here pins one of two promises. The subscription behaves like any other — same
 * billing day, same cadence, same hour of the day it moves. And nothing that costs the
 * customer anything happens to it: no charge job, no ledger row, no order, and no card
 * saved later quietly turning it into a paying subscription.
 */
class NoChargeSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    /** 13:00 in Israel — past any sensible billing hour, on a day that is not a month-end. */
    private const NOW_UTC = '2026-09-16 10:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW_UTC, 'UTC'));
        AppSetting::put('billing_hour', '9');
        config(['billing.kill_switch' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_a_due_no_charge_subscription_moves_to_its_next_cycle_and_nothing_is_charged(): void
    {
        $subscription = $this->subscription(PaymentState::NO_CHARGE, '2026-09-16');

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        $this->assertSame('2026-10-16', $subscription->fresh()->next_charge_at->toDateString());

        // The money half of the promise, asked three ways.
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
        $this->assertSame(0, PaymentLedger::query()->count());

        $event = ActivityEvent::query()
            ->where('subscription_id', $subscription->id)
            ->where('kind', Timeline::KIND_PLAN_UPDATED)
            ->sole();

        $this->assertSame('2026-09-16', $event->details['charge_date_from']);
        $this->assertSame('2026-10-16', $event->details['charge_date_to']);
    }

    public function test_a_subscription_left_months_behind_lands_on_the_first_cycle_ahead_in_one_move(): void
    {
        // Entered by an admin with an old date. Moving it costs nothing, so it is not held
        // for a person the way a paying subscription is — it simply catches up.
        $subscription = $this->subscription(PaymentState::NO_CHARGE, '2026-05-05');

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        $this->assertSame('2026-10-05', $subscription->fresh()->next_charge_at->toDateString(), 'the billing day is kept');
        $this->assertSame(1, ActivityEvent::query()
            ->where('subscription_id', $subscription->id)
            ->where('kind', Timeline::KIND_PLAN_UPDATED)
            ->count(), 'one move, one row — not a row per month walked over');
    }

    public function test_a_two_month_plan_steps_two_months(): void
    {
        $subscription = $this->subscription(PaymentState::NO_CHARGE, '2026-09-16', frequencyMonths: 2);

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        $this->assertSame('2026-11-16', $subscription->fresh()->next_charge_at->toDateString());
    }

    public function test_a_subscription_that_is_not_due_is_left_alone(): void
    {
        $subscription = $this->subscription(PaymentState::NO_CHARGE, '2026-09-20');

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        $this->assertSame('2026-09-20', $subscription->fresh()->next_charge_at->toDateString());
        $this->assertSame(0, ActivityEvent::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_paused_and_cancelled_subscriptions_do_not_move(): void
    {
        $paused = $this->subscription(PaymentState::NO_CHARGE, '2026-09-16', SubscriptionStatus::PAUSED);
        $cancelled = $this->subscription(PaymentState::NO_CHARGE, '2026-09-16', SubscriptionStatus::CANCELLED);

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        $this->assertSame('2026-09-16', $paused->fresh()->next_charge_at->toDateString());
        $this->assertSame('2026-09-16', $cancelled->fresh()->next_charge_at->toDateString());
    }

    public function test_a_paying_subscription_is_still_charged_and_its_date_is_left_to_the_charge(): void
    {
        $paying = $this->subscription(PaymentState::PAYME, '2026-09-16', withCard: true);

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        Queue::assertPushed(ChargeSubscriptionJob::class, 1);
        // The job is faked, so nothing ran: a date that moved anyway would mean the advancer
        // touched a paying row — and the charge would then key on the wrong cycle.
        $this->assertSame('2026-09-16', $paying->fresh()->next_charge_at->toDateString());
    }

    public function test_nothing_moves_before_the_billing_hour(): void
    {
        // 05:00 in Israel.
        Carbon::setTestNow(Carbon::parse('2026-09-16 02:00:00', 'UTC'));
        $subscription = $this->subscription(PaymentState::NO_CHARGE, '2026-09-16');

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        $this->assertSame('2026-09-16', $subscription->fresh()->next_charge_at->toDateString());
    }

    public function test_nothing_moves_while_billing_is_switched_off(): void
    {
        config(['billing.kill_switch' => true]);
        $subscription = $this->subscription(PaymentState::NO_CHARGE, '2026-09-16');

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        $this->assertSame('2026-09-16', $subscription->fresh()->next_charge_at->toDateString());
    }

    public function test_charging_it_by_hand_is_refused_before_any_money_is_touched(): void
    {
        // Even WITH a card on file: the payment state is the decision, not the card.
        $subscription = $this->subscription(PaymentState::NO_CHARGE, '2026-09-16', withCard: true, amount: 153.90);

        $result = app(ChargeOrchestrator::class)->charge($subscription);

        $this->assertFalse($result['success']);
        $this->assertSame('no_charge', $result['status']);
        $this->assertSame(0, PaymentLedger::query()->count());
        $this->assertSame('2026-09-16', $subscription->fresh()->next_charge_at->toDateString());
    }

    public function test_the_customer_sees_an_ordinary_subscription_and_is_never_asked_for_a_card(): void
    {
        $presented = StorefrontPresenter::subscription($this->subscription(PaymentState::NO_CHARGE, '2026-09-16'));

        // The storefront contract is frozen: payme|icount only. `icount` is what makes the
        // theme show the "update your card" banner, which is exactly what must not happen.
        $this->assertSame('payme', $presented['integration_source']);
        $this->assertFalse($presented['requires_card_update']);
    }

    public function test_saving_a_card_does_not_start_billing_it(): void
    {
        $subscription = $this->subscription(PaymentState::NO_CHARGE, '2026-09-16');

        $lifted = app(CardUpdateService::class)->liftCardUpdateWall($subscription->customer);

        $this->assertSame(0, $lifted);
        $this->assertSame(PaymentState::NO_CHARGE, $subscription->fresh()->payment_state);
    }

    public function test_the_admin_screen_offers_no_charge_button_and_raises_no_card_warning(): void
    {
        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription(PaymentState::NO_CHARGE, '2026-09-20');

        $this->get(SubscriptionResource::getUrl('view', ['record' => $subscription]))
            ->assertOk()
            ->assertSee(__('subscriptions.pay_no_charge'))
            ->assertDontSee(__('subscriptions.action_charge_now'))
            ->assertDontSee(__('subscriptions.card_update_required'));
    }

    // === Fixtures ===

    private function subscription(
        PaymentState $state,
        string $dueDate,
        SubscriptionStatus $status = SubscriptionStatus::ACTIVE,
        int $frequencyMonths = 1,
        bool $withCard = false,
        ?float $amount = null,
    ): Subscription {
        $customer = Customer::query()->create(['email' => uniqid('nc', true).'@example.com']);

        if ($withCard) {
            PaymentMethod::query()->create([
                'customer_id' => $customer->id,
                'gateway' => 'payme',
                'buyer_key' => 'bk',
                'is_active' => true,
                'source' => 'card_update',
                'captured_at' => now(),
            ]);
        }

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => $state->value,
            'frequency_months' => $frequencyMonths,
            'next_charge_at' => Carbon::parse($dueDate)->startOfDay(),
            'next_charge_amount' => $amount,
        ]);
        $subscription->forceFill(['status' => $status->value])->save();

        return $subscription->fresh();
    }
}
