<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\Dog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\ChargePreview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The breakdown behind the number.
 *
 * The screen used to show one figure — the stored draft total — with no account of itself,
 * so a changed rule, a swapped product or a stale stored amount were all invisible until
 * they surfaced on an invoice. This is the arithmetic shown BEFORE the charge, and its one
 * unbreakable property is that it agrees with the resolver that actually bills.
 */
class ChargePreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The store's live rule ships as a data migration; these tests build their own.
        DiscountRule::query()->delete();
    }

    private function variant(string $id, float $price, array $tags = []): void
    {
        $product = Product::query()->create([
            'shopify_product_id' => 'p-'.$id,
            'title' => 'Food '.$id,
            'tags' => $tags,
        ]);

        ProductVariant::query()->create([
            'shopify_variant_id' => $id,
            'product_id' => $product->id,
            'title' => '1.5kg',
            'price' => $price,
        ]);
    }

    private function subscription(array $variantIds, ?float $stored = null): Subscription
    {
        $customer = Customer::query()->create([
            'email' => uniqid('p', true).'@x.co',
            'shopify_customer_id' => (string) random_int(1000, 999999),
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_amount' => $stored,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        Dog::query()->create([
            'customer_id' => $customer->id,
            'subscription_id' => $subscription->id,
            'name' => 'Rex',
            'status' => 'active',
            'selected_variants' => array_map(fn ($id) => 'gid://shopify/ProductVariant/'.$id, $variantIds),
            'addons_products' => [],
        ]);

        return $subscription->fresh();
    }

    public function test_it_prices_from_the_store_and_then_applies_the_rule(): void
    {
        $this->variant('100', 171.00);
        DiscountRule::query()->create(['name' => 'הנחת מנוי', 'percent' => 10]);

        $preview = ChargePreview::for($this->subscription(['100']));

        $this->assertSame(171.00, $preview['subtotal']);
        $this->assertSame('הנחת מנוי', $preview['discount_name']);
        $this->assertSame(17.10, $preview['discount_amount']);
        $this->assertSame(153.90, $preview['total']);
    }

    public function test_a_line_scoped_rule_discounts_only_the_lines_it_matched(): void
    {
        // The store's real shape: 10% off the food, nothing off the excluded product.
        $this->variant('100', 200.00);
        $this->variant('9991874183472', 100.00);

        DiscountRule::query()->create([
            'name' => 'הנחת מנוי',
            'percent' => 10,
            'scope' => DiscountRule::SCOPE_MATCHING_LINES,
            'excluded_variant_ids' => ['9991874183472'],
        ]);

        $preview = ChargePreview::for($this->subscription(['100', '9991874183472']));

        $this->assertSame(300.00, $preview['subtotal']);
        $this->assertSame(20.00, $preview['discount_amount'], '10% of the ₪200 line, never of the ₪300 order');
        $this->assertSame(280.00, $preview['total']);

        // The screen marks WHICH line got it, so an exclusion can be seen working.
        $discounted = array_column($preview['lines'], 'discounted', 'variant_id');
        $this->assertTrue($discounted['100']);
        $this->assertFalse($discounted['9991874183472']);
    }

    public function test_no_rules_means_the_customer_pays_the_store_price(): void
    {
        $this->variant('100', 171.00);

        $preview = ChargePreview::for($this->subscription(['100']));

        $this->assertNull($preview['discount_name']);
        $this->assertSame(0.0, $preview['discount_amount']);
        $this->assertSame(171.00, $preview['total']);
    }

    public function test_it_calls_out_a_stored_amount_that_no_longer_matches(): void
    {
        /*
         * This is the ₪118 invoice, caught one screen earlier. The stored figure is what
         * will actually be charged, so when it disagrees with the products and rules in
         * front of you, saying nothing is how the gap reaches a customer's card.
         */
        $this->variant('100', 290.00);
        DiscountRule::query()->create(['name' => 'הנחת מנוי', 'percent' => 10]);

        $preview = ChargePreview::for($this->subscription(['100'], stored: 172.00));

        $this->assertSame(261.00, $preview['total']);
        $this->assertSame(172.00, $preview['stored']);
        $this->assertFalse($preview['matches_stored']);
    }

    public function test_a_matching_stored_amount_raises_nothing(): void
    {
        $this->variant('100', 171.00);
        DiscountRule::query()->create(['name' => 'הנחת מנוי', 'percent' => 10]);

        $preview = ChargePreview::for($this->subscription(['100'], stored: 153.90));

        $this->assertTrue($preview['matches_stored']);
    }

    public function test_an_unpriced_product_is_named_rather_than_silently_dropped(): void
    {
        // A line nobody can price is the one thing that makes the real charge disagree
        // with this preview — so it is reported, not quietly left out of the sum.
        $this->variant('100', 171.00);

        $preview = ChargePreview::for($this->subscription(['100', '404']));

        $this->assertSame(171.00, $preview['subtotal']);
        $this->assertSame(['404'], $preview['unpriced']);
    }

    public function test_it_previews_the_lines_being_edited_rather_than_the_saved_ones(): void
    {
        // The whole point in the order editor: the money must answer the edit in progress.
        $this->variant('100', 171.00);
        $this->variant('200', 60.00);
        DiscountRule::query()->create(['name' => 'הנחת מנוי', 'percent' => 10]);

        $subscription = $this->subscription(['100']);

        $preview = ChargePreview::for($subscription, [
            ['variant_id' => '100', 'quantity' => 1],
            ['variant_id' => '200', 'quantity' => 2],
        ]);

        $this->assertSame(291.00, $preview['subtotal']);   // 171 + (60 × 2)
        $this->assertSame(29.10, $preview['discount_amount']);
        $this->assertSame(261.90, $preview['total']);
    }
}
