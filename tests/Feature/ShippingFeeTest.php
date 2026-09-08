<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Customer;
use App\Models\DiscountRule;
use App\Models\Dog;
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
use App\Modules\MillsSubscriptions\Support\ChargePreview;
use App\Modules\MillsSubscriptions\Support\ShippingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Delivery on the recurring cycle: orders under a threshold pay a fee.
 *
 * The fee is decided in one place and has to show up in three — the draft (which is what
 * gets charged), the preview (which is what the admin sees), and the paid order (which is
 * what Shopify records). A fee on the draft but not the paid order leaves the order
 * underpaid by exactly that fee; a fee on the order but not the preview leaves the admin
 * unable to explain the number. So the tests hold all three to the same answer.
 */
class ShippingFeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DiscountRule::query()->delete();
        AppSetting::put(ShippingPolicy::SETTING_FEE, '29');
        AppSetting::put(ShippingPolicy::SETTING_THRESHOLD, '200');
        AppSetting::put(ShippingPolicy::SETTING_TITLE, 'משלוח עד הבית');
    }

    private function subscription(float $price, ?float $stored = null): Subscription
    {
        $product = Product::query()->create(['shopify_product_id' => 'p-'.$price, 'title' => 'Food']);
        ProductVariant::query()->create([
            'shopify_variant_id' => (string) (int) ($price * 100),
            'product_id' => $product->id,
            'title' => '1.5kg',
            'price' => $price,
        ]);

        $customer = Customer::query()->create([
            'email' => uniqid('s', true).'@x.co',
            'shopify_customer_id' => (string) random_int(1000, 999999),
            'first_name' => 'א', 'last_name' => 'ב', 'address1' => 'רחוב 1', 'city' => 'תל אביב',
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
            'selected_variants' => ['gid://shopify/ProductVariant/'.(int) ($price * 100)],
            'addons_products' => [],
        ]);

        return $subscription->fresh();
    }

    private function draftInput(Subscription $subscription): array
    {
        $service = new DraftOrderService(Mockery::mock(ShopifyAdminClient::class));
        $m = new ReflectionMethod($service, 'input');

        return $m->invoke($service, $subscription);
    }

    // --- the policy -----------------------------------------------------------

    public function test_an_order_under_the_threshold_pays_the_fee_and_one_at_it_does_not(): void
    {
        $this->assertSame(['title' => 'משלוח עד הבית', 'price' => 29.0], ShippingPolicy::feeFor(199.99));

        // "Spend ₪200 for free delivery" must be true at ₪200.00 exactly.
        $this->assertNull(ShippingPolicy::feeFor(200.00));
        $this->assertNull(ShippingPolicy::feeFor(594.00));
    }

    public function test_nothing_changes_for_anyone_until_a_fee_is_set(): void
    {
        AppSetting::put(ShippingPolicy::SETTING_FEE, '0');

        $this->assertFalse(ShippingPolicy::enabled());
        $this->assertNull(ShippingPolicy::feeFor(10.00));
    }

    // --- the three places that must agree -------------------------------------

    public function test_the_threshold_is_measured_after_the_discount(): void
    {
        /*
         * ₪210 of product less 10% is ₪189 — under the threshold. The customer pays ₪189
         * for the food, and that is the figure a free-delivery threshold is honestly
         * measured against, not the list price they never paid.
         */
        DiscountRule::query()->create(['name' => 'הנחת מנוי', 'percent' => 10]);
        $subscription = $this->subscription(210.00);

        $preview = ChargePreview::for($subscription);

        $this->assertSame(189.00, $preview['products_total']);
        $this->assertSame(29.00, $preview['shipping_fee']);
        $this->assertSame(218.00, $preview['total']);
    }

    public function test_the_draft_carries_the_delivery_line_so_the_customer_is_charged_it(): void
    {
        $input = $this->draftInput($this->subscription(150.00));

        $this->assertSame('משלוח עד הבית', $input['shippingLine']['title']);
        $this->assertSame('29.00', $input['shippingLine']['price']);
    }

    public function test_a_draft_over_the_threshold_states_free_delivery_explicitly(): void
    {
        // null, present — never omitted: a rebuilt draft must describe the whole order,
        // or the last one's delivery line survives underneath the new one.
        $input = $this->draftInput($this->subscription(594.00));

        $this->assertArrayHasKey('shippingLine', $input);
        $this->assertNull($input['shippingLine']);
    }

    public function test_the_paid_order_carries_the_same_delivery_line_and_still_reconciles(): void
    {
        /*
         * The charge was ₪179 = ₪150 of product + ₪29 delivery. Without the shipping line
         * on the order it totals ₪150 against a ₪179 payment; with it, and the gap worked
         * out as (products + delivery) − paid, it reconciles to the agora.
         */
        $subscription = $this->subscription(150.00);

        $ledger = PaymentLedger::query()->create([
            'subscription_id' => $subscription->id,
            'context' => 'recurring',
            'idempotency_key' => uniqid('k', true),
            'amount' => 179.00,
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

        (new OrderCreationService($client))->createPaidOrder($subscription, $ledger->fresh());

        $this->assertSame('29.00', $sent['shipping_lines'][0]['price']);
        $this->assertSame('משלוח עד הבית', $sent['shipping_lines'][0]['title']);

        // 150 + 29 = 179 = paid → no discount line, nothing outstanding.
        $this->assertArrayNotHasKey('discount_codes', $sent);
    }

    public function test_the_stale_amount_warning_accounts_for_delivery(): void
    {
        // A stored total that already includes the fee must not be flagged as wrong.
        $subscription = $this->subscription(150.00, stored: 179.00);

        $this->assertTrue(ChargePreview::for($subscription)['matches_stored']);
    }
}
