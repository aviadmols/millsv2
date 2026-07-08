<?php

namespace Tests\Unit;

use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Exceptions\IllegalTransitionException;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves CLAUDE.md law #4: status changes only via the guarded machine, illegal
 * moves throw, every accepted move writes a Timeline row, and the v1 edge mapping
 * is preserved.
 */
class SubscriptionStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(SubscriptionStatus $status): Subscription
    {
        $customer = Customer::query()->create(['email' => uniqid('c_', true).'@b.com']);
        $sub = new Subscription(['customer_id' => $customer->id, 'frequency_months' => 1]);
        $sub->forceFill(['status' => $status->value])->save();

        return $sub->refresh();
    }

    public function test_legal_transition_records_a_timeline_event(): void
    {
        $sub = $this->subscription(SubscriptionStatus::PENDING);

        $sub->transitionTo(SubscriptionStatus::ACTIVE);

        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->refresh()->status);
        $this->assertDatabaseHas('activity_events', [
            'subscription_id' => $sub->id,
            'kind' => Timeline::KIND_STATUS_CHANGED,
        ]);
    }

    public function test_illegal_transition_throws(): void
    {
        $sub = $this->subscription(SubscriptionStatus::CANCELLED);

        $this->expectException(IllegalTransitionException::class);
        $sub->transitionTo(SubscriptionStatus::ACTIVE); // cancelled is terminal
    }

    public function test_same_state_is_idempotent_noop(): void
    {
        $sub = $this->subscription(SubscriptionStatus::ACTIVE);

        $sub->transitionTo(SubscriptionStatus::ACTIVE);

        $this->assertSame(0, ActivityEvent::query()->count());
    }

    public function test_cancellation_is_reachable_from_every_non_terminal_state(): void
    {
        foreach ([SubscriptionStatus::PENDING, SubscriptionStatus::ACTIVE, SubscriptionStatus::PAUSED, SubscriptionStatus::PAST_DUE] as $state) {
            $sub = $this->subscription($state);
            $sub->transitionTo(SubscriptionStatus::CANCELLED);
            $this->assertSame(SubscriptionStatus::CANCELLED, $sub->refresh()->status);
        }
    }

    public function test_legacy_edge_mapping(): void
    {
        $this->assertSame('active', SubscriptionStatus::ACTIVE->toLegacy());
        $this->assertSame('disable', SubscriptionStatus::CANCELLED->toLegacy());
        $this->assertSame('pending', SubscriptionStatus::PAUSED->toLegacy());
        $this->assertSame('active', SubscriptionStatus::PAST_DUE->toLegacy());

        $this->assertSame(SubscriptionStatus::CANCELLED, SubscriptionStatus::fromLegacy('disable'));
        $this->assertSame(SubscriptionStatus::ACTIVE, SubscriptionStatus::fromLegacy('active'));
    }
}
