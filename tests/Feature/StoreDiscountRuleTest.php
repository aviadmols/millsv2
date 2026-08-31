<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Support\DiscountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The store's live discount policy, as it actually ships.
 *
 * 10% off the recurring order on everything except product 9991874183472 — installed as a
 * data migration rather than typed into a screen, because it is the policy the shop has
 * been running on all along and a store should be able to point at the rule it bills by.
 *
 * Deliberately does NOT clear the rules table: the whole point is to assert what a fresh
 * database really contains.
 */
class StoreDiscountRuleTest extends TestCase
{
    use RefreshDatabase;

    private const EXCLUDED = '9991874183472';

    public function test_the_rule_is_installed_and_active(): void
    {
        $rule = DiscountRule::query()->where('name', 'הנחת מנוי')->first();

        $this->assertNotNull($rule, 'the store discount rule must exist in every environment');
        $this->assertTrue($rule->is_active);
        $this->assertSame('10.00', (string) $rule->percent);

        // Line-scoped, and it MUST be: an order-wide 10% takes its cut off the excluded
        // product too, by way of the order total.
        $this->assertSame(DiscountRule::SCOPE_MATCHING_LINES, $rule->scope);
        $this->assertContains(self::EXCLUDED, $rule->excluded_variant_ids);
        $this->assertContains(self::EXCLUDED, $rule->excluded_product_ids);
    }

    public function test_it_discounts_the_food_and_leaves_the_excluded_product_alone(): void
    {
        $this->variant('100', 171.00);
        $this->variant(self::EXCLUDED, 50.00);

        $decision = DiscountResolver::for($this->subscription(), [
            ['variantId' => 'gid://shopify/ProductVariant/100', 'quantity' => 1],
            ['variantId' => 'gid://shopify/ProductVariant/'.self::EXCLUDED, 'quantity' => 1],
        ]);

        $this->assertSame(10.0, $decision['percent']);
        $this->assertSame(['100'], $decision['variant_ids']);

        // 10% of ₪171.00 — never of the ₪221.00 the order comes to.
        $this->assertSame(17.10, $decision['value']);
    }

    private function variant(string $id, float $price): void
    {
        $product = Product::query()->create([
            'shopify_product_id' => 'p-'.$id,
            'title' => 'Product '.$id,
        ]);

        ProductVariant::query()->create([
            'shopify_variant_id' => $id,
            'product_id' => $product->id,
            'title' => 'Variant '.$id,
            'price' => $price,
        ]);
    }

    private function subscription(): Subscription
    {
        $customer = Customer::query()->create([
            'email' => 'store@example.com',
            'shopify_customer_id' => '900800',
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription->fresh();
    }
}
