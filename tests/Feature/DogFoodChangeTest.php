<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Change a dog's food, and the next order changes with it.
 *
 * The upcoming order IS the next charge — its total is stored as `next_charge_amount` and
 * that is the number handed to PayMe. So a dog whose flavour is swapped without the order
 * being rebuilt leaves the admin screen showing one product, the customer being charged for
 * another, and the box containing a third.
 */
class DogFoodChangeTest extends TestCase
{
    use RefreshDatabase;

    /** The draft Shopify would have returned, and a record of what we asked it to build. */
    private FakeDraftShopify $shopify;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shopify = new FakeDraftShopify;
        $this->app->instance(ShopifyAdminClient::class, $this->shopify);
    }

    /** @return array{0: Subscription, 1: Dog, 2: ProductVariant, 3: ProductVariant} */
    private function scenario(): array
    {
        $customer = Customer::query()->create([
            'email' => 'food@example.com',
            'shopify_customer_id' => '900888',
            'first_name' => 'Noa',
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(10),
            'next_charge_amount' => 153.90,
            'draft_order_id' => '5001',
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $product = Product::query()->create([
            'shopify_product_id' => '7001',
            'title' => 'Mills',
            'status' => 'active',
            'multiplier' => 1.0,
        ]);

        $salmon = ProductVariant::query()->create([
            'shopify_variant_id' => '111111',
            'product_id' => $product->id,
            'title' => '51g',
            'sku' => 'SB30 - אריזה יומית של 51 גרם',
            'price' => 171.00,
            'grams' => 51,
            'pack_size' => 30,
            'available' => true,
        ]);

        $chicken = ProductVariant::query()->create([
            'shopify_variant_id' => '222222',
            'product_id' => $product->id,
            'title' => '149g',
            'sku' => 'CH30 - אריזה יומית של 149 גרם',
            'price' => 299.00,
            'grams' => 149,
            'pack_size' => 30,
            'available' => true,
        ]);

        $dog = Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => 'Rex',
            'status' => 'active',
            'weight' => 10,
            'age' => 3,
            'selected_variants' => [(string) $salmon->shopify_variant_id],
        ]);

        return [$subscription, $dog, $salmon, $chicken];
    }

    public function test_swapping_the_dogs_food_rebuilds_the_upcoming_order(): void
    {
        [$subscription, $dog, , $chicken] = $this->scenario();

        $this->shopify->total = '299.00';

        $dog->update(['selected_variants' => [(string) $chicken->shopify_variant_id]]);

        // The draft Shopify was asked to build must contain the NEW variant, not the old one.
        $lines = $this->shopify->lastInput['lineItems'] ?? [];

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('222222', $lines[0]['variantId']);

        // And the amount PayMe will be asked for follows the draft — not the old ₪153.90.
        $this->assertSame('299.00', number_format((float) $subscription->fresh()->next_charge_amount, 2));
    }

    public function test_a_hand_edited_upcoming_order_is_dropped_when_the_food_changes(): void
    {
        [$subscription, $dog, $salmon, $chicken] = $this->scenario();

        // The admin had hand-edited the next order (3 packs of salmon)…
        $subscription->forceFill([
            'line_items_override' => [['variant_id' => (string) $salmon->shopify_variant_id, 'quantity' => 3]],
            'line_items_overridden_at' => now(),
        ])->save();

        // …and then changed what the dog actually eats.
        $dog->update(['selected_variants' => [(string) $chicken->shopify_variant_id]]);

        // Keeping the edit would silently ship the OLD food the admin just replaced.
        $this->assertNull($subscription->fresh()->line_items_override);

        $lines = $this->shopify->lastInput['lineItems'] ?? [];
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('222222', $lines[0]['variantId']);
        $this->assertSame(1, $lines[0]['quantity']);
    }

    public function test_renaming_a_dog_does_not_touch_shopify(): void
    {
        [, $dog] = $this->scenario();

        $dog->update(['name' => 'Rexy']);

        // The order did not change, so there is nothing to rebuild. Calling Shopify on every
        // save would burn the API budget and rewrite drafts for no reason.
        $this->assertSame(0, $this->shopify->calls);
    }

    public function test_a_shopify_failure_never_blocks_the_save(): void
    {
        [, $dog, , $chicken] = $this->scenario();

        $this->shopify->fail = true;

        $dog->update(['selected_variants' => [(string) $chicken->shopify_variant_id]]);

        // The DB is the truth. Refusing to record what the admin did because a downstream
        // call failed would be worse than a stale draft the next rebuild fixes.
        $this->assertSame([(string) $chicken->shopify_variant_id], $dog->fresh()->selected_variants);
    }
}

/**
 * A Shopify that answers draft-order mutations from memory and remembers what it was asked
 * to build — so the test can assert on the ORDER, not on the HTTP call.
 */
class FakeDraftShopify extends ShopifyAdminClient
{
    public int $calls = 0;

    public bool $fail = false;

    public string $total = '153.90';

    /** @var array<string, mixed> */
    public array $lastInput = [];

    public function __construct()
    {
        parent::__construct(null);
    }

    public function isConnected(): bool
    {
        return true;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function graphql(string $query, array $variables = []): array
    {
        $this->calls++;
        $this->lastInput = $variables['input'] ?? [];

        if ($this->fail) {
            return ['errors' => [['message' => 'shopify is down']]];
        }

        $mutation = str_contains($query, 'draftOrderUpdate') ? 'draftOrderUpdate' : 'draftOrderCreate';

        return ['data' => [$mutation => [
            'draftOrder' => [
                'id' => 'gid://shopify/DraftOrder/5001',
                'name' => '#D1',
                'status' => 'OPEN',
                'totalPriceSet' => ['shopMoney' => ['amount' => $this->total, 'currencyCode' => 'ILS']],
                'subtotalPriceSet' => ['shopMoney' => ['amount' => $this->total, 'currencyCode' => 'ILS']],
                'lineItems' => ['nodes' => []],
            ],
            'userErrors' => [],
        ]]];
    }
}
