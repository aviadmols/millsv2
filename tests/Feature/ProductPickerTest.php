<?php

namespace Tests\Feature;

use App\Filament\Resources\Subscriptions\SubscriptionResource;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Choosing this dog's products.
 *
 * The picker offers the WHOLE shop — an admin adding a product by hand may be adding a
 * treat, an accessory, anything, and a list that carries only the subscription flavours
 * is a list that stops where the person's question begins. The engine's opinion lives in
 * a button beside it instead of a filter on it.
 */
class ProductPickerTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $title, array $tags, int $grams, int $pack, ?string $sku = null): ProductVariant
    {
        $product = Product::query()->create([
            'shopify_product_id' => (string) random_int(1000, 999999),
            'title' => $title,
            'status' => 'active',
            'multiplier' => 1.0,
            'collections' => ['כלבים'],
            'tags' => $tags,
        ]);

        return ProductVariant::query()->create([
            'shopify_variant_id' => (string) random_int(100000, 9999999),
            'product_id' => $product->id,
            'title' => "{$grams}g",
            'sku' => $sku ?? "AA{$pack} - אריזה יומית של {$grams} גרם",
            'grams' => $grams,
            'pack_size' => $pack,
            'available' => true,
        ]);
    }

    private function subscription(): Subscription
    {
        $customer = Customer::query()->create(['email' => 'picker@example.com', 'shopify_customer_id' => '5150']);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->addDays(5),
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => 'רקסי',
            'status' => 'active',
            'weight' => 10,
            'age' => 3,
            'activity' => 1,
            'body' => 1,
            'neutered' => true,
        ]);

        return $subscription;
    }

    public function test_the_picker_carries_the_whole_shop_not_only_subscription_flavours(): void
    {
        // A treat is not a subscription flavour and the engine would never suggest it —
        // which is exactly why hiding it made the picker useless for adding one.
        $food = $this->product('עוף, בטטה', ['עוף'], 90, 30);
        $treat = $this->product('חטיף עוף מיובש', ['חטיפים'], 0, 0, 'TREAT-1');

        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription();

        $this->get(SubscriptionResource::getUrl('edit', ['record' => $subscription]))
            ->assertOk()
            ->assertSee((string) $food->shopify_variant_id, false)
            ->assertSee((string) $treat->shopify_variant_id, false);
    }

    public function test_the_suggestion_splits_the_two_pack_sizes(): void
    {
        /*
         * 30 a month of one flavour, or 15 each of two — that is the real question in
         * front of the person, so the suggestion is shown as two lists rather than one
         * mixed one where the two sizes of the same food sit next to each other.
         */
        $this->product('עוף, בטטה', ['עוף'], 90, 30);
        $this->product('עוף, בטטה', ['עוף'], 90, 15);

        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription();

        $this->get(SubscriptionResource::getUrl('edit', ['record' => $subscription]))
            ->assertOk()
            ->assertSee(__('subscriptions.suggest_products'), false);
    }

    public function test_an_imported_dogs_gid_variants_read_as_product_names(): void
    {
        /*
         * Imported dogs hold GIDs — the storefront contract for legacy records — and the
         * picker's badge showed the raw gid where a person expects a product name
         * (27 Aug, first imported subscription). The selection must read as names
         * whatever shape the ids arrived in.
         */
        $variant = $this->product('צבי, בטטה, תות עץ', ['צבי'], 93, 15);

        $this->actingAs(User::factory()->create());
        $subscription = $this->subscription();
        $subscription->dogs->first()->forceFill([
            'selected_variants' => ['gid://shopify/ProductVariant/'.$variant->shopify_variant_id],
        ])->save();

        $this->get(SubscriptionResource::getUrl('edit', ['record' => $subscription]))
            ->assertOk()
            ->assertSee('צבי, בטטה, תות עץ', false)
            ->assertDontSee('gid:\/\/shopify\/ProductVariant', false);
    }

    public function test_the_edit_screen_renders_with_no_products_cached_at_all(): void
    {
        // An empty catalogue is what a store looks like before the first sync — the screen
        // must open anyway, or the person cannot get to the button that fixes it.
        $this->actingAs(User::factory()->create());

        $this->get(SubscriptionResource::getUrl('edit', ['record' => $this->subscription()]))
            ->assertOk();
    }
}
