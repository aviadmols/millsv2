<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Jobs\RefreshUpcomingOrdersJob;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Repricing the whole book.
 *
 * `next_charge_amount` is a stored copy of the upcoming Shopify draft's total, so a change
 * to how orders are priced — dropping the subscriber discount, a price change in the store
 * — reaches nobody until their draft is rebuilt. This is the sweep that applies it, and the
 * rules it must not break are all about money: never invent a price, never abandon the run
 * because one subscription failed, and never quietly touch a subscription nobody bills.
 */
class RefreshUpcomingOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(array $overrides = []): Subscription
    {
        $customer = Customer::query()->create([
            'email' => uniqid('c', true).'@x.co',
            'shopify_customer_id' => (string) random_int(1000, 999999),
        ]);

        $subscription = new Subscription;
        $subscription->fill(array_merge([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(3),
            'next_charge_amount' => 153.90,
            'draft_order_id' => '12345',
        ], $overrides));

        $subscription->forceFill([
            'status' => $overrides['status'] ?? SubscriptionStatus::ACTIVE->value,
        ])->save();

        return $subscription->fresh();
    }

    public function test_the_sweep_queues_every_subscription_that_has_an_upcoming_order(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        $active = $this->subscription();
        $paused = $this->subscription(['status' => SubscriptionStatus::PAUSED->value]);

        // Nothing to rebuild: no draft yet, and one that will never be billed again.
        $this->subscription(['draft_order_id' => null]);
        $this->subscription(['status' => SubscriptionStatus::CANCELLED->value]);

        Livewire::test(ListSubscriptions::class)
            ->callAction('refreshUpcomingOrders');

        Queue::assertPushed(RefreshUpcomingOrdersJob::class, function (RefreshUpcomingOrdersJob $job) use ($active, $paused) {
            sort($job->subscriptionIds);

            return $job->subscriptionIds === collect([$active->id, $paused->id])->sort()->values()->all();
        });
    }

    public function test_the_work_is_chunked_so_a_job_never_outlives_the_queue_release_window(): void
    {
        Queue::fake();
        $this->actingAs(User::factory()->create());

        foreach (range(1, RefreshUpcomingOrdersJob::CHUNK_SIZE + 3) as $ignored) {
            $this->subscription();
        }

        Livewire::test(ListSubscriptions::class)->callAction('refreshUpcomingOrders');

        // One full chunk plus the remainder — never a single job carrying the whole book.
        Queue::assertPushed(RefreshUpcomingOrdersJob::class, 2);
    }

    public function test_a_rebuilt_order_updates_the_amount_that_will_be_charged(): void
    {
        $subscription = $this->subscription();

        $drafts = Mockery::mock(DraftOrderService::class);
        $drafts->shouldReceive('refresh')->once()->andReturnUsing(function (Subscription $s) {
            // What the service really does: stores the draft's new total.
            $s->forceFill(['next_charge_amount' => 171.00])->save();

            return [];
        });

        (new RefreshUpcomingOrdersJob([$subscription->id]))->handle($drafts);

        $this->assertSame('171.00', (string) $subscription->fresh()->next_charge_amount);
    }

    public function test_one_subscription_shopify_cannot_price_does_not_abandon_the_rest(): void
    {
        /*
         * A variant the store no longer sells makes ONE draft unbuildable. Letting that
         * throw would strand every customer after it in the chunk — and the failed one must
         * keep its existing amount rather than be handed a guess.
         */
        $broken = $this->subscription();
        $fine = $this->subscription();

        $drafts = Mockery::mock(DraftOrderService::class);
        $drafts->shouldReceive('refresh')
            ->with(Mockery::on(fn (Subscription $s) => $s->id === $broken->id))
            ->andThrow(new RuntimeException('variant not found'));
        $drafts->shouldReceive('refresh')
            ->with(Mockery::on(fn (Subscription $s) => $s->id === $fine->id))
            ->andReturnUsing(function (Subscription $s) {
                $s->forceFill(['next_charge_amount' => 171.00])->save();

                return [];
            });

        (new RefreshUpcomingOrdersJob([$broken->id, $fine->id]))->handle($drafts);

        $this->assertSame('153.90', (string) $broken->fresh()->next_charge_amount, 'a failed lookup must not change the price');
        $this->assertSame('171.00', (string) $fine->fresh()->next_charge_amount);
    }
}
