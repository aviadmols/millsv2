<?php

namespace Tests\Feature;

use App\Filament\Widgets\SystemHealth;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Does the recurring charge actually happen, from the scheduler all the way to the ledger?
 *
 * There are TWO moving parts and only one of them is obvious. `mills:dispatch-due` does not
 * charge anyone — it queues a job and returns. A scheduler with no queue worker behind it runs
 * happily every five minutes, piles the jobs up in a database table, and bills nobody, while
 * every dashboard light stays green. That is the failure this file exists to make impossible
 * to ship.
 */
class BillingRunsEndToEndTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payme.api_url', 'https://payme.test');
        config()->set('payme.seller_id', 'SELLER1');

        /*
         * A REAL queue, not `sync`.
         *
         * The test suite runs on QUEUE_CONNECTION=sync, where dispatch() executes the job
         * inline — which is precisely the arrangement that does not exist in production and
         * that hides the bug this file is about. On sync, "the chain works" would be proved by
         * a chain with no queue in it.
         */
        config()->set('queue.default', 'database');
    }

    private function dueSubscription(): Subscription
    {
        $customer = Customer::query()->create([
            'email' => 'due@example.com',
            'shopify_customer_id' => '900555',
        ]);

        PaymentMethod::query()->create([
            'customer_id' => $customer->id,
            'gateway' => 'payme',
            'buyer_key' => 'bk_live',
            'is_active' => true,
            'source' => 'card_update',
            'captured_at' => now(),
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->subMinutes(5),   // due
            'next_charge_amount' => 153.90,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription;
    }

    public function test_the_dispatcher_queues_a_charge_but_does_not_perform_it(): void
    {
        Queue::fake();

        $this->dueSubscription();

        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        // This is the whole point: the scheduler hands the work to a QUEUE. On its own it has
        // charged precisely nobody.
        Queue::assertPushed(ChargeSubscriptionJob::class);
    }

    public function test_the_charge_reaches_payme_only_once_the_worker_runs_it(): void
    {
        Http::fake([
            'https://payme.test/generate-sale' => Http::response([
                'status_code' => 0,
                'payme_sale_id' => 'sale_live_1',
            ]),
        ]);

        $subscription = $this->dueSubscription();

        // The real chain, with nothing faked in between: dispatch → queue → worker → PayMe.
        $this->artisan('mills:dispatch-due')->assertExitCode(0);
        $this->artisan('queue:work', ['--queue' => 'charges', '--stop-when-empty' => true])
            ->assertExitCode(0);

        $ledger = PaymentLedger::query()->where('subscription_id', $subscription->id)->firstOrFail();

        $this->assertSame(LedgerStatus::SUCCEEDED, $ledger->status);
        $this->assertSame('153.90', (string) $ledger->amount);

        // And the cycle moved on, so the next run does not charge the same month again.
        $this->assertTrue($subscription->fresh()->next_charge_at->isFuture());
    }

    public function test_the_dashboard_says_so_when_charges_are_queued_and_nobody_is_taking_them(): void
    {
        $this->dueSubscription();

        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        // The worker is dead: the job has been sitting there.
        \DB::table('jobs')->update(['created_at' => now()->subHour()->getTimestamp()]);

        $this->actingAs(User::factory()->create());

        // A green CRON light over a dead worker is the most dangerous screen this app could
        // show — "billing ran 2 minutes ago" while not one customer is being charged.
        Livewire::test(SystemHealth::class)
            ->assertSee(__('dashboard.health_worker_stuck', ['count' => 1]));
    }
}
