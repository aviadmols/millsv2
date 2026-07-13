<?php

namespace Tests\Feature;

use App\Filament\Widgets\MillsStats;
use App\Filament\Widgets\UpcomingCharges;
use App\Filament\Widgets\UpcomingOrders;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\Subscription;
use App\Models\User;
use Livewire\Livewire;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\DashboardMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The home screen.
 *
 * Two rules it must never break:
 *
 *  - Revenue comes from the LEDGER — money that actually arrived — and never from the
 *    subscriptions table, which only ever knew what the plan hoped for.
 *  - The upcoming totals count only what will REALLY be charged. A subscription that is
 *    paused, still waiting on a card, or has no known amount is money we cannot collect,
 *    and a dashboard that sums it is lying to the person reading it.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function customer(string $email = 'dash@example.com'): Customer
    {
        return Customer::query()->create(['email' => $email, 'shopify_customer_id' => (string) random_int(1000, 99999)]);
    }

    private function subscription(array $overrides = []): Subscription
    {
        $subscription = new Subscription;
        $subscription->fill(array_merge([
            'customer_id' => $this->customer(uniqid('c', true).'@x.co')->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(3),
            'next_charge_amount' => 100.00,
        ], $overrides));

        $subscription->forceFill([
            'status' => $overrides['status'] ?? SubscriptionStatus::ACTIVE->value,
        ])->save();

        return $subscription->fresh();
    }

    private function charge(float $amount, string $when, string $status = 'succeeded'): void
    {
        $row = PaymentLedger::query()->create([
            'context' => 'recurring',
            'idempotency_key' => uniqid('k', true),
            'amount' => $amount,
            'currency' => 'ILS',
            'executed_at' => $when,
        ]);

        $row->forceFill(['status' => $status])->save();
    }

    // --- revenue comes from the ledger ---------------------------------------

    public function test_revenue_counts_only_money_that_actually_arrived(): void
    {
        $this->charge(100, now()->subDays(5)->toDateTimeString());
        $this->charge(50, now()->subDays(10)->toDateTimeString());

        // A failed charge took no money and must not appear as revenue.
        $this->charge(999, now()->subDays(2)->toDateTimeString(), LedgerStatus::FAILED->value);

        // Nor may a charge from outside the window.
        $this->charge(777, now()->subDays(45)->toDateTimeString());

        $revenue = DashboardMetrics::revenue(now()->subDays(30), now());

        $this->assertSame(150.0, $revenue);
        $this->assertSame(2, DashboardMetrics::chargeCount(now()->subDays(30), now()));
        $this->assertSame(1, DashboardMetrics::failedCount(now()->subDays(30), now()));
    }

    // --- upcoming counts only what is really collectable ----------------------

    public function test_upcoming_totals_exclude_money_that_cannot_be_collected(): void
    {
        $this->subscription(['next_charge_at' => now()->addDays(2), 'next_charge_amount' => 100]);

        // Paused → the dispatcher never selects it.
        $this->subscription([
            'status' => SubscriptionStatus::PAUSED->value,
            'next_charge_at' => now()->addDays(2),
            'next_charge_amount' => 500,
        ]);

        // Still waiting on a card → billing skips it.
        $this->subscription([
            'payment_state' => PaymentState::NEEDS_CARD_UPDATE->value,
            'next_charge_at' => now()->addDays(2),
            'next_charge_amount' => 500,
        ]);

        $week = DashboardMetrics::upcoming(7);

        $this->assertSame(1, $week['count']);
        $this->assertSame(100.0, $week['total'], 'a paused or card-blocked subscription is not revenue');
    }

    public function test_a_subscription_with_no_known_amount_is_counted_but_called_out(): void
    {
        $this->subscription(['next_charge_at' => now()->addDays(2), 'next_charge_amount' => null]);

        $week = DashboardMetrics::upcoming(7);

        // It IS due — hiding it would be worse. But it contributes nothing to the total,
        // and it is reported as a problem, because it will abort when the biller reaches it.
        $this->assertSame(1, $week['count']);
        $this->assertSame(0.0, $week['total']);
        $this->assertSame(1, $week['unknown_amount']);
    }

    public function test_the_overdue_backlog_is_reported_separately(): void
    {
        $this->subscription(['next_charge_at' => now()->subDays(20), 'next_charge_amount' => 153.90]);
        $this->subscription(['next_charge_at' => now()->addDays(2), 'next_charge_amount' => 100]);

        $overdue = DashboardMetrics::overdue();

        // With billing not yet running this is the most important number on the page, and it
        // must not be buried inside "next 30 days".
        $this->assertSame(1, $overdue['count']);
        $this->assertSame(153.90, $overdue['total']);
    }

    // --- trends must not lie --------------------------------------------------

    public function test_growth_from_zero_is_reported_as_unknown_not_as_a_hundred_percent(): void
    {
        // The usual dashboard lie: 0 → 5 is not "+100%", it is undefined.
        $this->assertNull(DashboardMetrics::trend(5, 0));
        $this->assertSame(0.0, DashboardMetrics::trend(0, 0));
        $this->assertSame(100.0, DashboardMetrics::trend(200, 100));
        $this->assertSame(-50.0, DashboardMetrics::trend(50, 100));
    }

    // --- it renders -----------------------------------------------------------

    public function test_the_home_screen_renders(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertSuccessful();
    }

    public function test_the_revenue_card_shows_money_that_actually_arrived(): void
    {
        $this->actingAs(User::factory()->create());
        $this->charge(153.90, now()->subDay()->toDateTimeString());

        // Widgets are lazy Livewire components — they are not in the page's first HTML, so
        // they have to be driven directly.
        Livewire::test(MillsStats::class)
            ->assertSee('₪153.90')
            ->assertSee('1 charges');
    }

    public function test_the_upcoming_card_shows_what_is_about_to_be_billed(): void
    {
        $this->actingAs(User::factory()->create());

        $this->subscription(['next_charge_at' => now()->addDays(2), 'next_charge_amount' => 153.90]);
        $this->subscription(['next_charge_at' => now()->subDays(9), 'next_charge_amount' => 200.00]);

        Livewire::test(UpcomingCharges::class)
            ->assertSee('₪153.90')      // due within the week
            ->assertSee('₪200.00');     // and the overdue backlog, called out separately
    }

    public function test_the_upcoming_orders_table_lists_who_is_about_to_be_charged(): void
    {
        $this->actingAs(User::factory()->create());

        $subscription = $this->subscription(['next_charge_at' => now()->addDays(2), 'next_charge_amount' => 153.90]);

        Livewire::test(UpcomingOrders::class)
            ->assertCanSeeTableRecords([$subscription]);
    }
}
