<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Dog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\StorefrontPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The personal area prices a subscription from OUR catalog, not from the storefront.
 *
 * It used to send bare variant ids and let the theme fetch each price from Shopify's own
 * /variants/{id}.js. That 404s for any product Shopify does not publish — and a real customer
 * was subscribed to "סלמון, תפוח אדמה - לא נמכר", a DRAFT product. Every line came back
 * empty and their whole personal area showed "—" for every total, while our own
 * product_variants table held the correct ₪382 the entire time.
 *
 * A subscription outlives a product's life on the shelf. Pricing it must not depend on
 * whether that product is still for sale to new customers.
 */
class PersonalAreaPricingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Subscription, 1: ProductVariant} */
    private function scenario(string $productStatus = 'draft'): array
    {
        $customer = Customer::query()->create([
            'email' => 'pricing@example.com',
            'shopify_customer_id' => '900555',
        ]);

        $product = Product::query()->create([
            'shopify_product_id' => '6572993642653',
            // The real one: a product that is no longer sold, but is still shipped to the
            // customers who already subscribe to it.
            'title' => 'סלמון, תפוח אדמה - לא נמכר',
            'status' => $productStatus,
            'multiplier' => 1.0,
        ]);

        $variant = ProductVariant::query()->create([
            'shopify_variant_id' => '39357427548317',
            'product_id' => $product->id,
            'title' => '348g',
            'sku' => 'SP30 - אריזה יומית של 348 גרם',
            'price' => 382.00,
            'grams' => 348,
            'pack_size' => 30,
            'available' => true,
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => 'איזי',
            'status' => 'active',
            'selected_variants' => [(string) $variant->shopify_variant_id],
        ]);

        return [$subscription->fresh(), $variant];
    }

    public function test_each_line_carries_its_own_name_and_price(): void
    {
        [$subscription] = $this->scenario();

        $dogs = StorefrontPresenter::subscriptionProducts($subscription)['dogs'];
        $line = array_values($dogs)[0]['subscription_products'][0];

        // The theme uses these directly and never asks the storefront, so a draft product
        // prices exactly like a published one.
        $this->assertSame('39357427548317', $line['variant_id']);
        $this->assertArrayHasKey('name', $line);
        $this->assertArrayHasKey('price', $line);
    }

    public function test_the_price_is_in_agorot_because_that_is_what_the_theme_divides(): void
    {
        [$subscription] = $this->scenario();

        $dogs = StorefrontPresenter::subscriptionProducts($subscription)['dogs'];
        $line = array_values($dogs)[0]['subscription_products'][0];

        // formatPrice() divides by 100. Sending shekels would price a ₪382 pack at ₪3.82 —
        // and it would look plausible enough that nobody would question it.
        $this->assertSame(38200, $line['price']);
    }

    public function test_a_product_shopify_no_longer_publishes_still_has_a_price(): void
    {
        // The exact production case: the storefront 404s on this variant, and it does not
        // matter, because the price never came from there.
        [$subscription] = $this->scenario(productStatus: 'draft');

        $dogs = StorefrontPresenter::subscriptionProducts($subscription)['dogs'];
        $line = array_values($dogs)[0]['subscription_products'][0];

        $this->assertSame(38200, $line['price']);
        $this->assertStringContainsString('לא נמכר', $line['name']);
    }

    public function test_a_variant_missing_from_the_catalog_still_lists_the_line(): void
    {
        [$subscription] = $this->scenario();

        Dog::query()->first()->update(['selected_variants' => ['111111111']]);

        $dogs = StorefrontPresenter::subscriptionProducts($subscription->fresh())['dogs'];
        $line = array_values($dogs)[0]['subscription_products'][0];

        // Dropping the line would hide a product the customer is being shipped. It is listed
        // without a price instead, so the gap is visible rather than silently absent.
        $this->assertSame('111111111', $line['variant_id']);
        $this->assertArrayNotHasKey('price', $line);
    }

    public function test_a_dog_with_no_products_produces_no_lines(): void
    {
        [$subscription] = $this->scenario();

        Dog::query()->first()->update(['selected_variants' => []]);

        $dogs = StorefrontPresenter::subscriptionProducts($subscription->fresh())['dogs'];

        $this->assertSame([], array_values($dogs)[0]['subscription_products']);
    }
}
