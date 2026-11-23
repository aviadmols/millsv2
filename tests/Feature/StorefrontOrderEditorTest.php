<?php

namespace Tests\Feature;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayResult;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\ChargeOrchestrator;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Support\StorefrontToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer edits their upcoming order (2026-11-12): food lives on the
 * dog; extras (tasting boxes, snacks, toys) on the subscription. Both repeat every order.
 */
class StorefrontOrderEditorTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Subscription $subscription;

    private Dog $dog;

    protected function setUp(): void
    {
        parent::setUp();

        config(['shopify.storefront_token_secret' => 'test-storefront-secret']);

        $food = Product::query()->create(['shopify_product_id' => 'p-food', 'title' => 'סלמון', 'status' => 'active', 'product_type' => 'מזון']);
        ProductVariant::query()->create(['shopify_variant_id' => '1001', 'product_id' => $food->id, 'title' => '3kg / 30', 'price' => 171, 'pack_size' => 30, 'grams' => 51]);
        ProductVariant::query()->create(['shopify_variant_id' => '1002', 'product_id' => $food->id, 'title' => '3kg / 15', 'price' => 95, 'pack_size' => 15, 'grams' => 51]);

        $box = Product::query()->create(['shopify_product_id' => 'p-box', 'title' => 'מארז טעימות', 'status' => 'active', 'product_type' => 'מזון']);
        ProductVariant::query()->create(['shopify_variant_id' => '2001', 'product_id' => $box->id, 'title' => '10 אריזות', 'price' => 59]);

        $toy = Product::query()->create(['shopify_product_id' => 'p-toy', 'title' => 'צעצוע', 'status' => 'active', 'product_type' => 'אביזרים']);
        ProductVariant::query()->create(['shopify_variant_id' => '3001', 'product_id' => $toy->id, 'title' => 'Default Title', 'price' => 40]);

        $shipping = Product::query()->create(['shopify_product_id' => 'p-ship', 'title' => 'משלוח', 'status' => 'active']);
        ProductVariant::query()->create(['shopify_variant_id' => '9001', 'product_id' => $shipping->id, 'title' => 'Default Title', 'price' => 29]);

        $this->customer = Customer::query()->create(['email' => 'edit@example.com', 'shopify_customer_id' => '7001']);

        $this->subscription = new Subscription;
        $this->subscription->fill(['customer_id' => $this->customer->id, 'payment_state' => PaymentState::PAYME->value, 'frequency_months' => 1, 'next_charge_at' => now()->subDay(), 'next_charge_amount' => 171]);
        $this->subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $this->dog = Dog::withoutEvents(fn () => Dog::query()->create([
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->subscription->id,
            'name' => 'רקס',
            'status' => 'active',
            'selected_variants' => ['1001'],
        ]));
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.StorefrontToken::mint('7001')];
    }

    public function test_the_catalog_splits_food_from_extras_and_leaves_out_untyped_products(): void
    {
        $response = $this->getJson('/storefront/me/catalog', $this->headers())->assertOk();

        $this->assertEqualsCanonicalizing(['1001', '1002'], array_column($response->json('data.food'), 'variant_id'));
        $this->assertEqualsCanonicalizing(['2001', '3001'], array_column($response->json('data.addons'), 'variant_id'));
        $this->assertSame(5900, collect($response->json('data.addons'))->firstWhere('variant_id', '2001')['price']);
    }

    public function test_food_is_changed_permanently_on_the_dog(): void
    {
        $this->patchJson("/storefront/me/dogs/{$this->dog->id}/food", ['variant_ids' => ['1002'], 'quantity' => 2], $this->headers())
            ->assertOk();

        $dog = $this->dog->fresh();
        $this->assertSame(['1002'], $dog->selected_variants);
        $this->assertTrue((bool) $dog->double_food);
    }

    public function test_an_extra_cannot_be_saved_as_food_and_food_needs_at_least_one_line(): void
    {
        $this->patchJson("/storefront/me/dogs/{$this->dog->id}/food", ['variant_ids' => ['2001']], $this->headers())
            ->assertStatus(422)->assertJsonPath('error', 'invalid_food');

        $this->patchJson("/storefront/me/dogs/{$this->dog->id}/food", ['variant_ids' => []], $this->headers())
            ->assertStatus(422)->assertJsonPath('error', 'food_required');

        $this->patchJson("/storefront/me/dogs/{$this->dog->id}/food", ['variant_ids' => ['1001'], 'quantity' => 3], $this->headers())
            ->assertStatus(422)->assertJsonPath('error', 'invalid_quantity');

        $this->assertSame(['1001'], $this->dog->fresh()->selected_variants);
    }

    public function test_extras_are_saved_on_the_subscription_and_ship_with_the_order(): void
    {
        $this->patchJson("/storefront/me/subscription/{$this->subscription->id}/addons", [
            'items' => [['variant_id' => '2001', 'quantity' => 2], ['variant_id' => '3001', 'quantity' => 1]],
        ], $this->headers())->assertOk();

        $subscription = $this->subscription->fresh();
        $this->assertSame([['variant_id' => '2001', 'quantity' => 2], ['variant_id' => '3001', 'quantity' => 1]], $subscription->recurring_addons);

        // The upcoming order now carries the dog's food AND the extras.
        $lines = app(DraftOrderService::class)->lineItems($subscription);
        $this->assertCount(3, $lines);
        $this->assertSame(2, collect($lines)->firstWhere('variantId', 'gid://shopify/ProductVariant/2001')['quantity']);

        // The food on the dog is untouched — extras are not food.
        $this->assertSame(['1001'], $this->dog->fresh()->selected_variants);
    }

    public function test_food_cannot_be_added_as_an_extra(): void
    {
        $this->patchJson("/storefront/me/subscription/{$this->subscription->id}/addons", [
            'items' => [['variant_id' => '1002', 'quantity' => 1]],
        ], $this->headers())->assertStatus(422)->assertJsonPath('error', 'invalid_addon');
    }

    public function test_the_extras_stay_for_the_following_orders_after_a_charge(): void
    {
        $this->subscription->forceFill(['recurring_addons' => [['variant_id' => '2001', 'quantity' => 1]], 'next_charge_amount' => 207.00])->save(); // (171 food + 59 extra) after the 10% rule
        PaymentMethod::query()->create(['customer_id' => $this->customer->id, 'gateway' => 'payme', 'buyer_key' => 'BK', 'is_active' => true, 'captured_at' => now()]);

        $gateway = new class implements PaymentGateway
        {
            public function chargeWithReference(string $reference, int $amountAgorot, string $idempotencyKey, array $opts = []): GatewayResult
            {
                return GatewayResult::success('sale_1', ['payme_status_code' => 0]);
            }

            public function lookup(string $idempotencyKey): GatewayResult
            {
                return GatewayResult::success('sale_1');
            }
        };

        $result = (new ChargeOrchestrator($gateway))->charge($this->subscription->fresh());
        $this->assertTrue($result['success']);
        // Every change repeats every order (Aviad, 2026-11-12) — a charge does not clear them.
        $this->assertSame([['variant_id' => '2001', 'quantity' => 1]], $this->subscription->fresh()->recurring_addons);
        $this->assertSame(['1001'], $this->dog->fresh()->selected_variants);
    }

    public function test_the_add_extra_button_keeps_the_extra_on_the_subscription_not_the_dog(): void
    {
        $this->patchJson("/storefront/me/dogs/{$this->dog->id}/addons/add", ['variantId' => '2001'], $this->headers())->assertOk();

        $this->assertEmpty($this->dog->fresh()->addons_products);
        $this->assertSame([['variant_id' => '2001', 'quantity' => 1]], $this->subscription->fresh()->recurring_addons);
    }

    public function test_a_blocked_or_cancelled_subscription_cannot_be_edited(): void
    {
        $this->subscription->forceFill(['payment_state' => PaymentState::NEEDS_CARD_UPDATE->value])->save();
        $this->patchJson("/storefront/me/dogs/{$this->dog->id}/food", ['variant_ids' => ['1002']], $this->headers())
            ->assertStatus(403)->assertJsonPath('error', 'icount_requires_card_update');

        $this->subscription->forceFill(['payment_state' => PaymentState::PAYME->value])->save();
        $this->subscription->fresh()->transitionTo(SubscriptionStatus::CANCELLED);
        $this->patchJson("/storefront/me/subscription/{$this->subscription->id}/addons", ['items' => []], $this->headers())
            ->assertStatus(422)->assertJsonPath('error', 'subscription_cancelled');
    }

    public function test_me_returns_the_extras_and_the_portion(): void
    {
        $this->subscription->forceFill(['recurring_addons' => [['variant_id' => '2001', 'quantity' => 2]]])->save();

        $response = $this->getJson('/storefront/me', $this->headers())->assertOk();

        $sub = $response->json('data.subscriptions.0');
        $this->assertSame('2001', $sub['recurring_addons'][0]['variant_id']);
        $this->assertSame(2, $sub['recurring_addons'][0]['quantity']);
        $this->assertSame(5900, $sub['recurring_addons'][0]['price']);
        $this->assertFalse($response->json('data.dogs.0.double_food'));
    }

    public function test_the_customer_sets_and_clears_a_standing_order_note(): void
    {
        $this->patchJson("/storefront/me/subscription/{$this->subscription->id}/note", ['note' => ' להשאיר ליד הדלת '], $this->headers())
            ->assertOk()
            ->assertJsonPath('data.order_note', 'להשאיר ליד הדלת');

        $this->assertSame('להשאיר ליד הדלת', $this->subscription->fresh()->order_note);
        $this->assertSame('להשאיר ליד הדלת', $this->getJson('/storefront/me', $this->headers())->json('data.subscriptions.0.order_note'));

        $this->patchJson("/storefront/me/subscription/{$this->subscription->id}/note", ['note' => ''], $this->headers())->assertOk();
        $this->assertNull($this->subscription->fresh()->order_note);
    }

    public function test_a_note_over_500_characters_is_refused(): void
    {
        $this->patchJson("/storefront/me/subscription/{$this->subscription->id}/note", ['note' => str_repeat('א', 501)], $this->headers())
            ->assertStatus(422);

        $this->assertNull($this->subscription->fresh()->order_note);
    }
}
