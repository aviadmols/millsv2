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
use App\Modules\MillsSubscriptions\Services\Shopify\OrderCreationService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use App\Modules\MillsSubscriptions\Support\ShippingPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * VAT on the orders we create.
 *
 * Shopify does not work tax out for an order created through the API — its tax engine runs
 * at checkout, and an API order carries exactly the tax it is given. Given none, the order
 * said zero and the invoicing app printed that: invoice 134216 showed the food at "+0%
 * מע״מ", the delivery as "פטור ממע״מ", then found the only line it thought taxable was the
 * DISCOUNT, billed 18% of it, and produced minus ₪2.75 of VAT on a ₪191 invoice.
 */
class OrderVatTest extends TestCase
{
    use RefreshDatabase;

    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        DiscountRule::query()->delete();
        AppSetting::put('vat_rate', '18');
        AppSetting::put(ShippingPolicy::SETTING_FEE, '0');
        AppSetting::put(ShippingPolicy::SETTING_THRESHOLD, '0');
    }

    private function createOrder(float $productPrice, float $charged): void
    {
        $product = Product::query()->create(['shopify_product_id' => 'p-1', 'title' => 'Food']);
        ProductVariant::query()->create([
            'shopify_variant_id' => '111',
            'product_id' => $product->id,
            'title' => '30 pack',
            'price' => $productPrice,
        ]);

        $customer = Customer::query()->create([
            'email' => 'vat@example.com', 'shopify_customer_id' => '901000',
            'first_name' => 'מנוי', 'last_name' => 'לבדיקה',
            'address1' => 'הצורף 7', 'city' => 'חולון',
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
            'name' => 'Rex', 'status' => 'active',
            'selected_variants' => ['gid://shopify/ProductVariant/111'],
            'addons_products' => [],
        ]);

        $ledger = PaymentLedger::query()->create([
            'subscription_id' => $subscription->id,
            'context' => 'recurring',
            'idempotency_key' => uniqid('k', true),
            'amount' => $charged,
            'currency' => 'ILS',
            'executed_at' => now(),
        ]);
        $ledger->forceFill(['status' => LedgerStatus::SUCCEEDED->value])->save();

        $client = Mockery::mock(ShopifyAdminClient::class);
        $client->shouldReceive('isConnected')->andReturnTrue();
        $client->shouldReceive('restPost')->once()->andReturnUsing(function (string $path, array $body) {
            $this->sent = $body['order'];

            return ['order' => ['id' => 1, 'name' => '#74905']];
        });

        (new OrderCreationService($client))->createPaidOrder($subscription->fresh(), $ledger->fresh());
    }

    /** @return float the VAT on every taxed line, added up */
    private function totalTax(): float
    {
        $sum = 0.0;

        foreach (array_merge($this->sent['line_items'] ?? [], $this->sent['shipping_lines'] ?? []) as $line) {
            foreach ($line['tax_lines'] ?? [] as $tax) {
                $sum += (float) $tax['price'];
            }
        }

        return round($sum, 2);
    }

    public function test_the_order_states_the_vat_its_prices_already_contain(): void
    {
        // ₪180 of food charged in full: the invoice must read ₪152.54 + ₪27.46 VAT.
        $this->createOrder(productPrice: 180.00, charged: 180.00);

        $this->assertTrue($this->sent['taxes_included'], 'the prices we send are gross, and Shopify must be told so');

        $tax = $this->sent['line_items'][0]['tax_lines'][0];
        $this->assertSame(0.18, $tax['rate']);
        $this->assertSame('27.46', $tax['price']);
        $this->assertSame(__('subscriptions.vat'), $tax['title']);

        // ₪180 − ₪27.46 = ₪152.54, which is 180 / 1.18.
        $this->assertSame(152.54, round(180.00 - (float) $tax['price'], 2));
    }

    public function test_the_tax_is_worked_out_on_what_was_actually_paid_after_the_discount(): void
    {
        /*
         * The invoice's real failure. ₪180 of food, ₪164.19 charged after a discount: the
         * VAT is the part inside the ₪164.19, not inside the ₪180 — and certainly not 18%
         * of the discount line, which is what produced minus ₪2.75.
         */
        $this->createOrder(productPrice: 180.00, charged: 164.19);

        $this->assertSame(round(164.19 * 18 / 118, 2), $this->totalTax());
        $this->assertGreaterThan(0, $this->totalTax(), 'VAT can never come out negative');
    }

    public function test_delivery_is_taxed_like_everything_else(): void
    {
        // The invoice printed the delivery line as "פטור ממע״מ". Nothing here is exempt.
        AppSetting::put(ShippingPolicy::SETTING_FEE, '29');
        AppSetting::put(ShippingPolicy::SETTING_THRESHOLD, '200');

        $this->createOrder(productPrice: 180.00, charged: 209.00);   // 180 + 29 delivery

        $this->assertNotEmpty($this->sent['shipping_lines'][0]['tax_lines']);
        $this->assertSame(round(209.00 * 18 / 118, 2), $this->totalTax());
    }

    public function test_the_tax_lines_add_up_to_the_vat_inside_the_charge_exactly(): void
    {
        // Split across two components, the shares must still total the VAT in the money —
        // a rounding error here is a cent nobody can explain on a tax document.
        AppSetting::put(ShippingPolicy::SETTING_FEE, '29');
        AppSetting::put(ShippingPolicy::SETTING_THRESHOLD, '200');

        $this->createOrder(productPrice: 177.77, charged: 206.77);

        $this->assertSame(round(206.77 * 18 / 118, 2), $this->totalTax());
    }

    public function test_a_shop_that_charges_no_vat_says_nothing_about_it(): void
    {
        AppSetting::put('vat_rate', '0');

        $this->createOrder(productPrice: 180.00, charged: 180.00);

        $this->assertArrayNotHasKey('tax_lines', $this->sent['line_items'][0]);
        $this->assertArrayNotHasKey('taxes_included', $this->sent);
    }
}
