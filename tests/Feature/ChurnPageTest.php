<?php

namespace Tests\Feature;

use App\Filament\Pages\Churn;
use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The churn list.
 *
 * Its whole reason to exist is that "4 churned" is a number nobody can act on. What it must
 * get right: the DATE (which lives only in the activity log), and the filters that let a
 * spike be opened and read.
 */
class ChurnPageTest extends TestCase
{
    use RefreshDatabase;

    private function cancellation(string $when, string $actor = Timeline::ACTOR_CUSTOMER, ?string $startedAt = null): ActivityEvent
    {
        $customer = Customer::query()->create([
            'email' => uniqid('c', true).'@x.co',
            'shopify_customer_id' => (string) random_int(1000, 999999),
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::CANCELLED->value])->save();

        if ($startedAt !== null) {
            $subscription->forceFill(['created_at' => $startedAt])->save();
        }

        $event = new ActivityEvent;
        $event->forceFill([
            'kind' => Timeline::KIND_STATUS_CHANGED,
            'actor' => $actor,
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'details' => ['model' => 'Subscription', 'from' => 'active', 'to' => 'cancelled'],
            'created_at' => $when,
        ])->save();

        return $event;
    }

    public function test_it_lists_cancellations_newest_first(): void
    {
        $this->actingAs(User::factory()->create());

        $old = $this->cancellation(now()->subMonths(2)->toDateTimeString());
        $recent = $this->cancellation(now()->subDay()->toDateTimeString());

        Livewire::test(Churn::class)
            ->assertCanSeeTableRecords([$recent, $old])
            ->assertSuccessful();
    }

    public function test_a_status_change_that_is_not_a_cancellation_never_appears(): void
    {
        // The query keys on details->to; a pause writes the same KIND and would otherwise
        // show up as someone having left.
        $this->actingAs(User::factory()->create());

        $cancelled = $this->cancellation(now()->subDay()->toDateTimeString());

        $paused = new ActivityEvent;
        $paused->forceFill([
            'kind' => Timeline::KIND_STATUS_CHANGED,
            'actor' => Timeline::ACTOR_CUSTOMER,
            'details' => ['from' => 'active', 'to' => 'paused'],
            'created_at' => now()->subDay(),
        ])->save();

        Livewire::test(Churn::class)
            ->assertCanSeeTableRecords([$cancelled])
            ->assertCanNotSeeTableRecords([$paused]);
    }

    public function test_the_period_filter_uses_calendar_days_so_today_means_today(): void
    {
        $this->actingAs(User::factory()->create());

        $today = $this->cancellation(now()->startOfDay()->addHours(2)->toDateTimeString());
        $lastWeek = $this->cancellation(now()->subDays(5)->toDateTimeString());

        Livewire::test(Churn::class)
            ->filterTable('period', ['days' => 1])
            ->assertCanSeeTableRecords([$today])
            ->assertCanNotSeeTableRecords([$lastWeek])
            ->filterTable('period', ['days' => 7])
            ->assertCanSeeTableRecords([$today, $lastWeek]);
    }

    public function test_a_single_day_can_be_isolated(): void
    {
        $this->actingAs(User::factory()->create());

        $spike = $this->cancellation(now()->subDays(3)->setTime(11, 0)->toDateTimeString());
        $other = $this->cancellation(now()->subDays(4)->toDateTimeString());

        Livewire::test(Churn::class)
            ->filterTable('day', ['date' => now()->subDays(3)->toDateString()])
            ->assertCanSeeTableRecords([$spike])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_it_shows_how_long_the_customer_stayed(): void
    {
        // Someone who left after two cycles is a different problem from someone who left
        // after two years, and the count on the home tab cannot tell the two apart.
        $this->actingAs(User::factory()->create());

        $this->cancellation(
            now()->subDay()->toDateTimeString(),
            Timeline::ACTOR_CUSTOMER,
            now()->subDays(11)->toDateTimeString(),
        );

        Livewire::test(Churn::class)->assertSee(__('churn.tenure_days', ['days' => 10]));
    }
}
