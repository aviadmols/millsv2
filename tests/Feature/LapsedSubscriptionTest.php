<?php

namespace Tests\Feature;

use App\Filament\Widgets\SystemHealth;
use App\Jobs\ChargeSubscriptionJob;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\ChargeOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A subscription that has fallen behind is never quietly caught up.
 *
 * This was live. A real customer sat at next_charge_at = 2026-05-25 while the scheduler was
 * not deployed. The moment billing came up, this is what the code would have done:
 *
 *   11:20  charge ₪153.90 (the May cycle) → advance to 25 June, which is STILL in the past
 *   11:25  charge ₪153.90 (the June cycle) → advance to 25 July → stop
 *
 * Two charges, ten minutes apart, for two boxes that were never shipped, with no human
 * anywhere near it. It is not a double charge — each cycle has its own idempotency key, so
 * every guard in the system says yes. That is exactly why it needed its own.
 */
class LapsedSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payme.api_url', 'https://payme.test');
        config()->set('payme.seller_id', 'SELLER1');
        config()->set('queue.default', 'database');

        Http::fake(['https://payme.test/generate-sale' => Http::response([
            'status_code' => 0,
            'payme_sale_id' => 'sale_1',
        ])]);
    }

    private function subscription(string $nextCharge, int $frequencyMonths = 1): Subscription
    {
        $customer = Customer::query()->create(['email' => 'lapsed'.uniqid().'@example.com']);

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
            'frequency_months' => $frequencyMonths,
            'next_charge_at' => $nextCharge,
            'next_charge_amount' => 153.90,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription;
    }

    // --- the line ------------------------------------------------------------

    public function test_a_subscription_due_yesterday_still_charges_normally(): void
    {
        // Being a little late is ordinary. The guard must not break ordinary billing.
        $subscription = $this->subscription(now()->subDay()->toDateString());

        $this->assertFalse($subscription->isTooFarBehindToCharge());

        $this->artisan('mills:dispatch-due')->assertExitCode(0);
        $this->artisan('queue:work', ['--queue' => 'charges', '--stop-when-empty' => true]);

        $this->assertSame(1, PaymentLedger::query()->where('subscription_id', $subscription->id)->count());
    }

    public function test_a_subscription_more_than_a_cycle_behind_is_not_charged(): void
    {
        $subscription = $this->subscription(now()->subMonths(2)->toDateString());

        $this->assertTrue($subscription->isTooFarBehindToCharge());

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        // Not even queued.
        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_the_two_month_plan_gets_two_months_of_grace(): void
    {
        // A bi-monthly subscription due six weeks ago has not missed a cycle at all.
        $onTime = $this->subscription(now()->subWeeks(6)->toDateString(), frequencyMonths: 2);
        $lapsed = $this->subscription(now()->subMonths(3)->toDateString(), frequencyMonths: 2);

        $this->assertFalse($onTime->isTooFarBehindToCharge());
        $this->assertTrue($lapsed->isTooFarBehindToCharge());
    }

    // --- the wall ------------------------------------------------------------

    public function test_the_orchestrator_refuses_even_when_the_job_reaches_it_anyway(): void
    {
        // A job queued before the subscription fell behind, a retry, an admin re-dispatch —
        // the dispatcher's check is a filter, THIS is the wall.
        $subscription = $this->subscription(now()->subMonths(2)->toDateString());

        $result = app(ChargeOrchestrator::class)->charge($subscription);

        $this->assertFalse($result['success']);
        $this->assertSame('too_far_behind', $result['status']);

        // No ledger row, no PayMe call, no money.
        $this->assertSame(0, PaymentLedger::query()->count());
        Http::assertNothingSent();
    }

    public function test_the_catch_up_stampede_cannot_happen(): void
    {
        // The real scenario, played out: run the biller repeatedly, as the scheduler does
        // every five minutes, and confirm the customer is charged exactly zero times.
        $subscription = $this->subscription(now()->subMonths(3)->toDateString());

        for ($run = 0; $run < 4; $run++) {
            $this->artisan('mills:dispatch-due')->assertExitCode(0);
            $this->artisan('queue:work', ['--queue' => 'charges', '--stop-when-empty' => true]);
        }

        $this->assertSame(0, PaymentLedger::query()->count());
        $this->assertSame(
            now()->subMonths(3)->toDateString(),
            $subscription->fresh()->next_charge_at->toDateString(),
            'the due date must not creep forward either — it is the record of what was missed',
        );
    }

    // --- it must never be silent ---------------------------------------------

    public function test_the_dashboard_says_how_many_are_held_back(): void
    {
        $this->subscription(now()->subMonths(2)->toDateString());
        $this->subscription(now()->subMonths(5)->toDateString());
        $this->subscription(now()->addWeek()->toDateString());   // healthy

        $this->actingAs(User::factory()->create());

        // A subscription that quietly stops being billed, and never says so, is
        // indistinguishable from one that is being billed.
        Livewire::test(SystemHealth::class)
            ->assertSee(__('dashboard.health_behind_count', ['count' => 2]));
    }
}
