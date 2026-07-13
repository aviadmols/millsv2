<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\SubscriptionActions;
use App\Modules\MillsSubscriptions\Support\SubscriptionPricing;
use App\Support\ShopifyImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The subscription screen, pinned.
 *
 * Each of these was a real defect: the products rendered as a bare text list with no
 * picture; the order-history line items rendered EMPTY; every order in the history linked
 * to /orders/1 (the subscription's id, not the order's); and pausing/postponing did not
 * exist at all.
 */
class SubscriptionScreenTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGE = 'https://cdn.shopify.com/s/files/1/0446/products/Artboard31.png?v=1616402298';

    private function scenario(): array
    {
        $customer = Customer::query()->create([
            'email' => 'screen@example.com',
            'shopify_customer_id' => '900777',
            'first_name' => 'Dana',
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(10),
            'next_charge_amount' => 182.90,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $product = Product::query()->create([
            'shopify_product_id' => '7001',
            'title' => 'Salmon Micro',
            'status' => 'active',
            'multiplier' => 1.0,
            'collections' => ['כלבים'],
            'image_url' => self::IMAGE,
        ]);

        $variant = ProductVariant::query()->create([
            'shopify_variant_id' => '39357390782621',
            'product_id' => $product->id,
            'title' => '51g',
            'sku' => 'SB30 - אריזה יומית של 51 גרם',
            'price' => 171.00,
            'grams' => 51,
            'pack_size' => 30,
            'available' => true,
            'image_url' => self::IMAGE,
        ]);

        $dog = Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => 'Rex',
            'status' => 'active',
            'weight' => 3,
            'age' => 2,
            // Stored as a GID — the resolver must normalise it to find the numeric id.
            'selected_variants' => ['gid://shopify/ProductVariant/39357390782621'],
        ]);

        return [$customer, $subscription, $dog, $variant];
    }

    // --- images ---------------------------------------------------------------

    public function test_the_thumbnail_is_asked_for_at_a_sane_size_and_the_url_stays_valid(): void
    {
        // The raw asset is a 1 MB PNG. Every render MUST ask the CDN to resize it.
        $thumb = ShopifyImage::thumb(self::IMAGE);

        $this->assertStringContainsString('width=120', $thumb);

        // The stored URL already has ?v=… — appending with '?' would make it malformed
        // and Shopify would serve the original megabyte anyway.
        $this->assertStringContainsString('?v=1616402298&width=120', $thumb);
        $this->assertSame(1, substr_count($thumb, '?'), 'a URL must not end up with two query strings');
    }

    public function test_the_thumbnail_helper_leaves_foreign_urls_and_nulls_alone(): void
    {
        $this->assertNull(ShopifyImage::thumb(null));
        $this->assertNull(ShopifyImage::thumb(''));
        $this->assertSame('https://example.com/a.png', ShopifyImage::thumb('https://example.com/a.png'));

        // Idempotent: re-sizing an already-sized URL must not stack parameters.
        $once = ShopifyImage::thumb(self::IMAGE);
        $this->assertSame($once, ShopifyImage::thumb($once));
    }

    public function test_the_subscription_screen_shows_the_product_with_its_picture(): void
    {
        $this->actingAs(User::factory()->create());
        [, $subscription] = $this->scenario();

        $response = $this->get("/admin/subscriptions/{$subscription->id}")->assertSuccessful();

        $response->assertSee('Salmon Micro');                       // the product resolved…
        $response->assertSee('SB30 - אריזה יומית של 51 גרם', escape: false);
        $response->assertSee('width=120', escape: false);           // …and its image is rendered, sized
        $response->assertSee('cdn.shopify.com', escape: false);
    }

    public function test_a_product_that_vanished_from_the_catalog_is_shown_not_silently_dropped(): void
    {
        $this->actingAs(User::factory()->create());
        [, $subscription, $dog] = $this->scenario();

        $dog->forceFill(['selected_variants' => ['gid://shopify/ProductVariant/404404404']])->save();

        // A subscription billing for a product we cannot find must SAY SO.
        $this->get("/admin/subscriptions/{$subscription->id}")
            ->assertSuccessful()
            ->assertSee('404404404', escape: false);
    }

    // --- the amount -----------------------------------------------------------

    public function test_the_screen_shows_what_the_next_charge_will_cost(): void
    {
        $this->actingAs(User::factory()->create());
        [, $subscription] = $this->scenario();

        $this->get("/admin/subscriptions/{$subscription->id}")
            ->assertSuccessful()
            ->assertSee('182.90');
    }

    public function test_an_unknown_amount_is_never_guessed(): void
    {
        [, $subscription] = $this->scenario();

        $subscription->forceFill(['next_charge_amount' => null, 'meta' => []])->save();

        // Null, not 171.00. Charging a plausible-looking guess is the bug this prevents:
        // the real orders bill 182.90 where the product alone is 171.00.
        $this->assertNull(SubscriptionPricing::amount($subscription->fresh()));
        $this->assertSame(171.00, SubscriptionPricing::productsSubtotal($subscription->fresh()));
    }

    // --- pause / resume -------------------------------------------------------

    public function test_pausing_removes_the_subscription_from_the_billing_queue(): void
    {
        [, $subscription] = $this->scenario();
        $subscription->forceFill(['next_charge_at' => now()->subDay()])->save();   // due now

        $this->assertTrue($this->isDue($subscription), 'precondition: it is due');

        app(SubscriptionActions::class)->pause($subscription);

        $this->assertSame(SubscriptionStatus::PAUSED, $subscription->fresh()->status);
        $this->assertFalse($this->isDue($subscription->fresh()), 'a paused subscription must not be charged');
    }

    public function test_resuming_a_long_paused_subscription_is_not_a_surprise_charge(): void
    {
        [, $subscription] = $this->scenario();

        // Paused, and its charge date slid two months into the past.
        $subscription->forceFill(['next_charge_at' => now()->subMonths(2)])->save();
        $actions = app(SubscriptionActions::class);
        $actions->pause($subscription);

        $actions->resume($subscription->fresh());

        $resumed = $subscription->fresh();
        $this->assertSame(SubscriptionStatus::ACTIVE, $resumed->status);
        $this->assertTrue(
            $resumed->next_charge_at->isToday(),
            'a date left in the past would be charged instantly on resume — it must be pulled to today',
        );
    }

    // --- postpone -------------------------------------------------------------

    public function test_postponing_advances_the_charge_by_exactly_one_cycle(): void
    {
        [, $subscription] = $this->scenario();
        $before = $subscription->next_charge_at->copy();

        $next = app(SubscriptionActions::class)->postponeNextCharge($subscription);

        $this->assertTrue($next->isSameDay($before->copy()->addMonthNoOverflow()));
        $this->assertTrue($subscription->fresh()->next_charge_at->isSameDay($next));
    }

    public function test_postponing_a_bi_monthly_subscription_advances_two_months(): void
    {
        [, $subscription] = $this->scenario();
        $subscription->forceFill(['frequency_months' => 2])->save();
        $before = $subscription->next_charge_at->copy();

        $next = app(SubscriptionActions::class)->postponeNextCharge($subscription->fresh());

        $this->assertTrue($next->isSameDay($before->copy()->addMonthsNoOverflow(2)));
    }

    public function test_postponing_clears_a_pending_retry_backoff(): void
    {
        [, $subscription] = $this->scenario();
        $subscription->forceFill(['attempt_count' => 2, 'next_retry_at' => now()->addHours(4)])->save();

        app(SubscriptionActions::class)->postponeNextCharge($subscription->fresh());

        $fresh = $subscription->fresh();
        $this->assertSame(0, (int) $fresh->attempt_count, 'a postponement is not a failure');
        $this->assertNull($fresh->next_retry_at);
    }

    public function test_it_refuses_to_postpone_into_the_past(): void
    {
        [, $subscription] = $this->scenario();

        $this->expectException(RuntimeException::class);
        app(SubscriptionActions::class)->postponeNextCharge($subscription, now()->subDay());
    }

    // --- the subscriber discount ---------------------------------------------

    public function test_the_upcoming_order_carries_the_subscriber_discount(): void
    {
        [, $subscription] = $this->scenario();

        // Subscribers have never paid list price: the real orders bill the product less
        // exactly 10%. A draft built without it would OVERCHARGE every customer.
        $subscription = $subscription->fresh();
        $this->assertSame('10.00', (string) $subscription->discount_percent, 'the default is the rate the store actually uses');

        $input = $this->draftInput($subscription);

        $this->assertArrayHasKey('appliedDiscount', $input);
        $this->assertSame('PERCENTAGE', $input['appliedDiscount']['valueType']);
        $this->assertSame(10.0, $input['appliedDiscount']['value']);
    }

    public function test_a_zero_discount_subscription_gets_no_discount_line(): void
    {
        [, $subscription] = $this->scenario();
        $subscription->forceFill(['discount_percent' => 0])->save();

        $this->assertArrayNotHasKey('appliedDiscount', $this->draftInput($subscription->fresh()));
    }

    public function test_the_upcoming_order_carries_no_shipping_line(): void
    {
        [, $subscription] = $this->scenario();

        // Subscription delivery is free — the historical ₪29 belongs to the old one-off
        // checkout, not the recurring cycle.
        $this->assertArrayNotHasKey('shippingLine', $this->draftInput($subscription));
    }

    /** @return array<string, mixed> */
    private function draftInput(Subscription $subscription): array
    {
        $service = app(\App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService::class);

        $method = new \ReflectionMethod($service, 'input');
        $method->setAccessible(true);

        return $method->invoke($service, $subscription);
    }

    // --- the guarded state machine is not bypassed ---------------------------

    public function test_the_actions_go_through_the_state_machine_and_land_on_the_timeline(): void
    {
        [$customer, $subscription] = $this->scenario();

        app(SubscriptionActions::class)->pause($subscription);

        $this->assertDatabaseHas('activity_events', [
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'kind' => 'status_changed',
        ]);
    }

    /** Mirrors DispatchDueSubscriptionsCommand's selection exactly. */
    private function isDue(Subscription $subscription): bool
    {
        return Subscription::query()
            ->whereKey($subscription->id)
            ->where('status', SubscriptionStatus::ACTIVE->value)
            ->where('payment_state', PaymentState::PAYME->value)
            ->whereNotNull('next_charge_at')
            ->where('next_charge_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->exists();
    }
}
