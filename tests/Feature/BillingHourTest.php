<?php

namespace Tests\Feature;

use App\Jobs\ChargeSubscriptionJob;
use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Charges run at the hour the admin chose — Israel time — and not a minute before.
 *
 * The scheduler ticks every five minutes around the clock and next_charge_at is a bare
 * date, so without the gate the first tick after midnight charged everyone: customers woke
 * to a 00:05 charge notification and a 00:06 order confirmation. Nobody reads those kindly.
 */
class BillingHourTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dueSubscription(): Subscription
    {
        $customer = Customer::query()->create(['email' => 'hour@example.com']);

        PaymentMethod::query()->create([
            'customer_id' => $customer->id,
            'gateway' => 'payme',
            'buyer_key' => 'bk',
            'is_active' => true,
            'source' => 'card_update',
            'captured_at' => now(),
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now('Asia/Jerusalem')->startOfDay(),
            'next_charge_amount' => 153.90,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription;
    }

    public function test_nothing_is_charged_before_the_chosen_hour(): void
    {
        AppSetting::put('billing_hour', '9');
        // 05:00 Israel (July = UTC+3) — the middle of the night for the customer.
        Carbon::setTestNow(Carbon::parse('2026-07-30 02:00:00', 'UTC'));

        $this->dueSubscription();

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }

    public function test_the_dashboard_light_stays_green_while_waiting(): void
    {
        AppSetting::put('billing_hour', '9');
        Carbon::setTestNow(Carbon::parse('2026-07-30 02:00:00', 'UTC'));

        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        // Waiting for 09:00 IS billing working. If last_run froze all night, the system
        // panel would cry "not running" every single morning.
        $this->assertNotNull(Cache::get('billing.dispatch.last_run'));
    }

    public function test_charges_dispatch_from_the_chosen_hour_onward(): void
    {
        AppSetting::put('billing_hour', '9');
        // 10:30 Israel — past the hour.
        Carbon::setTestNow(Carbon::parse('2026-07-30 07:30:00', 'UTC'));

        $this->dueSubscription();

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        Queue::assertPushed(ChargeSubscriptionJob::class);
    }

    public function test_the_default_hour_is_nine_not_midnight(): void
    {
        // Nothing configured: the safe default must still be a civilised hour.
        Carbon::setTestNow(Carbon::parse('2026-07-30 00:30:00', 'UTC'));   // 03:30 Israel

        $this->dueSubscription();

        Queue::fake();
        $this->artisan('mills:dispatch-due')->assertExitCode(0);

        Queue::assertNotPushed(ChargeSubscriptionJob::class);
    }
}
