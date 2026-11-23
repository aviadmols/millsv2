<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\Pages\EditSubscription;
use App\Filament\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Resources\Subscriptions\Pages\ViewSubscription;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\User;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Services\Shopify\OrderCreationService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use App\Modules\MillsSubscriptions\Services\SubscriptionActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Admin tools added 2026-11-23: change the next charge date (one or many), double
 * portion for the whole subscription, and a standing note on every Shopify order.
 */
class SubscriptionAdminToolsTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(array $overrides = []): Subscription
    {
        $product = Product::query()->create(['shopify_product_id' => uniqid('p', true), 'title' => 'Food']);
        $variant = ProductVariant::query()->create([
            'shopify_variant_id' => (string) random_int(100000, 999999),
            'product_id' => $product->id,
            'title' => '3kg',
            'price' => 171.00,
            'pack_size' => 30,
        ]);

        $customer = Customer::query()->create([
            'email' => uniqid('t', true).'@x.co',
            'shopify_customer_id' => (string) random_int(1000, 999999),
            'first_name' => 'א', 'last_name' => 'ב', 'address1' => 'רחוב 1', 'city' => 'תל אביב',
        ]);

        $subscription = new Subscription;
        $subscription->fill(array_merge([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(10)->startOfDay(),
        ], $overrides));
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        Dog::withoutEvents(fn () => Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => 'Rex',
            'status' => 'active',
            'selected_variants' => [(string) $variant->shopify_variant_id],
        ]));

        return $subscription->fresh();
    }

    // --- the next charge date ---------------------------------------------------

    public function test_the_date_can_be_brought_forward_and_pushed_back(): void
    {
        $subscription = $this->subscription();

        app(SubscriptionActions::class)->changeNextChargeDate($subscription, now()->addDays(3));
        $this->assertTrue($subscription->fresh()->next_charge_at->isSameDay(now()->addDays(3)));

        app(SubscriptionActions::class)->changeNextChargeDate($subscription->fresh(), now()->addDays(40));
        $this->assertTrue($subscription->fresh()->next_charge_at->isSameDay(now()->addDays(40)));

        $this->assertDatabaseHas('activity_events', ['subscription_id' => $subscription->id, 'kind' => 'note']);
    }

    public function test_today_is_refused_so_a_date_change_never_charges_on_the_spot(): void
    {
        $subscription = $this->subscription();

        $this->expectException(RuntimeException::class);
        app(SubscriptionActions::class)->changeNextChargeDate($subscription, now());
    }

    public function test_a_new_date_clears_the_retry_backoff(): void
    {
        $subscription = $this->subscription(['attempt_count' => 2, 'next_retry_at' => now()->addDay()]);

        app(SubscriptionActions::class)->changeNextChargeDate($subscription, now()->addDays(5));

        $this->assertSame(0, (int) $subscription->fresh()->attempt_count);
        $this->assertNull($subscription->fresh()->next_retry_at);
    }

    // --- double portion -----------------------------------------------------------

    public function test_double_portion_is_written_to_every_active_dog(): void
    {
        $this->app->instance(DraftOrderService::class, Mockery::mock(DraftOrderService::class)->shouldIgnoreMissing());
        $subscription = $this->subscription();

        $this->assertFalse(SubscriptionActions::hasDoubleFood($subscription));

        $changed = app(SubscriptionActions::class)->setDoubleFood($subscription, true);

        $this->assertSame(1, $changed);
        $this->assertTrue(SubscriptionActions::hasDoubleFood($subscription));
        $real = new DraftOrderService(Mockery::mock(ShopifyAdminClient::class));
        $this->assertSame(2, $real->lineItems($subscription->fresh())[0]['quantity'] ?? null);
    }

    // --- the standing order note --------------------------------------------------

    public function test_the_note_goes_on_the_draft_and_is_cleared_when_removed(): void
    {
        $subscription = $this->subscription();
        $subscription->forceFill(['order_note' => '  להשאיר אצל השכן  '])->saveQuietly();

        $service = new DraftOrderService(Mockery::mock(ShopifyAdminClient::class));
        $input = (new ReflectionMethod($service, 'input'))->invoke($service, $subscription->fresh());
        $this->assertSame('להשאיר אצל השכן', $input['note']);

        $subscription->forceFill(['order_note' => null])->saveQuietly();
        $input = (new ReflectionMethod($service, 'input'))->invoke($service, $subscription->fresh());
        $this->assertArrayHasKey('note', $input);
        $this->assertNull($input['note']);
    }

    public function test_the_note_goes_on_the_paid_order(): void
    {
        $subscription = $this->subscription();
        $subscription->forceFill(['order_note' => 'בלי פלסטיק'])->saveQuietly();

        $ledger = PaymentLedger::query()->create([
            'subscription_id' => $subscription->id,
            'context' => 'recurring',
            'idempotency_key' => uniqid('k', true),
            'amount' => 153.90,
            'currency' => 'ILS',
            'executed_at' => now(),
        ]);
        $ledger->forceFill(['status' => LedgerStatus::SUCCEEDED->value])->save();

        $sent = [];
        $client = Mockery::mock(ShopifyAdminClient::class);
        $client->shouldReceive('isConnected')->andReturnTrue();
        $client->shouldReceive('restPost')->once()->andReturnUsing(function (string $path, array $body) use (&$sent) {
            $sent = $body['order'];

            return ['order' => ['id' => 1, 'name' => '#1']];
        });

        (new OrderCreationService($client))->createPaidOrder($subscription->fresh(), $ledger->fresh());

        $this->assertSame('בלי פלסטיק', $sent['note']);
    }

    // --- the admin screens themselves --------------------------------------------

    public function test_the_edit_form_saves_double_portion_and_the_note(): void
    {
        $this->actingAs(User::factory()->create());
        $this->app->instance(DraftOrderService::class, Mockery::mock(DraftOrderService::class)->shouldIgnoreMissing());
        $subscription = $this->subscription();

        Livewire::test(EditSubscription::class, ['record' => $subscription->getRouteKey()])
            ->assertFormSet(['double_food' => false])
            ->fillForm(['double_food' => true, 'order_note' => 'להשאיר אצל השכן'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue(SubscriptionActions::hasDoubleFood($subscription));
        $this->assertSame('להשאיר אצל השכן', $subscription->fresh()->order_note);
    }

    public function test_the_more_menu_changes_the_next_charge_date(): void
    {
        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription();

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getRouteKey()])
            ->callAction('changeChargeDate', data: ['date' => now()->addDays(4)->toDateString()])
            ->assertHasNoActionErrors();

        $this->assertTrue($subscription->fresh()->next_charge_at->isSameDay(now()->addDays(4)));
    }

    public function test_the_bulk_action_moves_many_dates_and_skips_what_would_charge_today(): void
    {
        $this->actingAs(User::factory()->create());
        $far = $this->subscription(['next_charge_at' => now()->addDays(10)->startOfDay()]);
        $near = $this->subscription(['next_charge_at' => now()->addDays(2)->startOfDay()]);

        Livewire::test(ListSubscriptions::class)
            ->callTableBulkAction('bulkChangeChargeDate', [$far, $near], data: ['mode' => 'shift', 'days' => -3]);

        $this->assertTrue($far->fresh()->next_charge_at->isSameDay(now()->addDays(7)));
        // 2 days − 3 = yesterday: skipped, untouched, nobody charged on the spot.
        $this->assertTrue($near->fresh()->next_charge_at->isSameDay(now()->addDays(2)));
    }
}
