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
 * Which discount a recurring order gets, and off what.
 *
 * The rules decide money, so the things this file pins down are the ones that cost real
 * shekels when they are wrong: that a rule only fires when its conditions actually hold,
 * that "highest wins" means the most money off rather than the biggest percentage, and
 * that a deal typed by hand for one customer is never overwritten by a rule.
 */
class DiscountRulesTest extends TestCase
{
    use RefreshDatabase;

    private function variant(string $id, float $price, ?int $packSize = null, array $tags = []): ProductVariant
    {
        $product = Product::query()->create([
            'shopify_product_id' => 'p-'.$id,
            'title' => 'Product '.$id,
            'tags' => $tags,
        ]);

        return ProductVariant::query()->create([
            'shopify_variant_id' => $id,
            'product_id' => $product->id,
            'title' => 'Variant '.$id,
            'price' => $price,
            'pack_size' => $packSize,
        ]);
    }

    private function subscription(int $frequencyMonths = 1, float $manualDiscount = 0): Subscription
    {
        $customer = Customer::query()->create([
            'email' => uniqid('d', true).'@x.co',
            'shopify_customer_id' => (string) random_int(1000, 999999),
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => $frequencyMonths,
            'discount_percent' => $manualDiscount,
            'next_charge_at' => now()->addDays(3),
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription->fresh();
    }

    /** @return list<array{variantId: string, quantity: int}> */
    private function lines(array $idsToQuantities): array
    {
        $out = [];

        foreach ($idsToQuantities as $id => $qty) {
            $out[] = ['variantId' => 'gid://shopify/ProductVariant/'.$id, 'quantity' => $qty];
        }

        return $out;
    }

    public function test_no_rules_means_no_discount(): void
    {
        $this->variant('100', 171.00);

        $this->assertNull(DiscountResolver::for($this->subscription(), $this->lines(['100' => 1])));
    }

    public function test_a_rule_with_no_conditions_applies_to_everyone(): void
    {
        // The simplest thing anyone wants: a plain store-wide discount.
        $this->variant('100', 171.00);
        DiscountRule::query()->create(['name' => 'All', 'percent' => 10, 'scope' => DiscountRule::SCOPE_ORDER]);

        $decision = DiscountResolver::for($this->subscription(), $this->lines(['100' => 1]));

        $this->assertSame(10.0, $decision['percent']);
        $this->assertSame(17.10, $decision['value']);
    }

    public function test_a_frequency_condition_keeps_the_rule_off_other_plans(): void
    {
        $this->variant('100', 171.00);
        DiscountRule::query()->create([
            'name' => 'Two-month only', 'percent' => 10, 'frequency_months' => 2,
        ]);

        $this->assertNull(DiscountResolver::for($this->subscription(1), $this->lines(['100' => 1])));
        $this->assertNotNull(DiscountResolver::for($this->subscription(2), $this->lines(['100' => 1])));
    }

    public function test_a_tag_condition_follows_the_product_rather_than_a_hand_kept_list(): void
    {
        // The reason tags are worth supporting: a new product given the tag joins by itself.
        $this->variant('100', 171.00, null, ['Premium']);
        $this->variant('200', 50.00, null, ['Basic']);

        DiscountRule::query()->create(['name' => 'Premium', 'percent' => 10, 'tags' => ['premium']]);

        $this->assertNotNull(DiscountResolver::for($this->subscription(), $this->lines(['100' => 1])));
        $this->assertNull(DiscountResolver::for($this->subscription(), $this->lines(['200' => 1])));
    }

    public function test_pack_size_and_variant_conditions_both_select_lines(): void
    {
        $this->variant('100', 171.00, 30);
        $this->variant('200', 90.00, 15);

        DiscountRule::query()->create(['name' => '30-day', 'percent' => 10, 'pack_sizes' => [30]]);

        $this->assertNotNull(DiscountResolver::for($this->subscription(), $this->lines(['100' => 1])));
        $this->assertNull(DiscountResolver::for($this->subscription(), $this->lines(['200' => 1])));

        DiscountRule::query()->create(['name' => 'That variant', 'percent' => 5, 'variant_ids' => ['200']]);

        $this->assertSame(5.0, DiscountResolver::for($this->subscription(), $this->lines(['200' => 1]))['percent']);
    }

    public function test_matching_lines_scope_discounts_only_the_products_that_matched(): void
    {
        $this->variant('100', 100.00, null, ['premium']);
        $this->variant('200', 400.00, null, ['basic']);

        DiscountRule::query()->create([
            'name' => 'Premium only',
            'percent' => 10,
            'scope' => DiscountRule::SCOPE_MATCHING_LINES,
            'tags' => ['premium'],
        ]);

        $decision = DiscountResolver::for($this->subscription(), $this->lines(['100' => 1, '200' => 1]));

        $this->assertSame(DiscountRule::SCOPE_MATCHING_LINES, $decision['scope']);
        $this->assertSame(['100'], $decision['variant_ids']);
        // 10% of the ₪100 line only — NOT of the ₪500 order.
        $this->assertSame(10.0, $decision['value']);
    }

    public function test_the_rule_worth_the_most_money_wins_not_the_biggest_percentage(): void
    {
        /*
         * The whole reason rules are costed in shekels: 15% off a ₪40 add-on is not a
         * better deal than 5% off a ₪700 order, and comparing 15 against 5 says it is.
         */
        $this->variant('100', 700.00, null, ['food']);
        $this->variant('200', 40.00, null, ['addon']);

        DiscountRule::query()->create([
            'name' => 'Small but loud', 'percent' => 15,
            'scope' => DiscountRule::SCOPE_MATCHING_LINES, 'tags' => ['addon'],
        ]);
        DiscountRule::query()->create(['name' => 'Quietly bigger', 'percent' => 5]);

        $decision = DiscountResolver::for($this->subscription(), $this->lines(['100' => 1, '200' => 1]));

        $this->assertSame('Quietly bigger', $decision['rule_name']);
        $this->assertSame(37.00, $decision['value']);   // 5% of ₪740 beats 15% of ₪40
    }

    public function test_a_hand_set_discount_outranks_every_rule(): void
    {
        // Someone typed that number after promising it to a customer on the phone.
        $this->variant('100', 171.00);
        DiscountRule::query()->create(['name' => 'Store-wide', 'percent' => 30]);

        $decision = DiscountResolver::for($this->subscription(1, 12.5), $this->lines(['100' => 1]));

        $this->assertSame(12.5, $decision['percent']);
        $this->assertNull($decision['rule_id']);
    }

    public function test_an_inactive_rule_changes_nothing(): void
    {
        $this->variant('100', 171.00);
        DiscountRule::query()->create(['name' => 'Paused offer', 'percent' => 10, 'is_active' => false]);

        $this->assertNull(DiscountResolver::for($this->subscription(), $this->lines(['100' => 1])));
    }

    public function test_a_line_scoped_rule_with_no_product_conditions_still_discounts_the_order(): void
    {
        // Otherwise it would silently take nothing off and look broken on screen.
        $this->variant('100', 200.00);

        DiscountRule::query()->create([
            'name' => 'Line-scoped by mistake',
            'percent' => 10,
            'scope' => DiscountRule::SCOPE_MATCHING_LINES,
        ]);

        $decision = DiscountResolver::for($this->subscription(), $this->lines(['100' => 1]));

        $this->assertSame(DiscountRule::SCOPE_ORDER, $decision['scope']);
        $this->assertSame(20.0, $decision['value']);
    }

    public function test_an_unsynced_variant_is_never_priced_by_guesswork(): void
    {
        // We cannot cost a rule against a line we cannot see, and inventing a price is how
        // a customer gets billed a number nobody chose.
        DiscountRule::query()->create(['name' => 'Store-wide', 'percent' => 10]);

        $this->assertNull(DiscountResolver::for($this->subscription(), $this->lines(['999' => 1])));
    }

    public function test_quantity_counts_towards_what_a_rule_is_worth(): void
    {
        $this->variant('100', 100.00);
        DiscountRule::query()->create(['name' => 'Store-wide', 'percent' => 10]);

        $decision = DiscountResolver::for($this->subscription(), $this->lines(['100' => 2]));

        $this->assertSame(20.0, $decision['value']);
    }
}
