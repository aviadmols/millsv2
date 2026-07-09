<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_key_admin_pages_render(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->get('/admin')->assertSuccessful();
        $this->get('/admin/settings')->assertSuccessful();
        $this->get('/admin/subscriptions')->assertSuccessful();
        $this->get('/admin/customers')->assertSuccessful();
        $this->get('/admin/cron-runs')->assertSuccessful();
    }

    public function test_subscription_view_page_renders_with_owner_details(): void
    {
        $this->actingAs(User::factory()->create());

        $customer = Customer::query()->create(['email' => 'owner@example.com', 'shopify_customer_id' => '123', 'first_name' => 'Anat']);
        $sub = new Subscription([
            'customer_id' => $customer->id,
            'frequency_months' => 2,
            'payment_state' => PaymentState::PAYME->value,
            'next_charge_at' => now()->addMonth(),
        ]);
        $sub->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $this->get("/admin/subscriptions/{$sub->id}")
            ->assertSuccessful()
            ->assertSee('owner@example.com');
    }
}
