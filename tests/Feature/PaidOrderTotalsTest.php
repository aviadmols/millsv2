<?php

namespace Tests\Feature;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * The order we create must total exactly what we charged.
 *
 * Shopify derives an order's financial status from its TRANSACTIONS. An order whose lines
 * add up to ₪171.00 carrying a ₪153.90 sale is not "paid" — it is PARTIALLY PAID, with
 * ₪17.10 outstanding, and it sits in the merchant's admin looking like a customer who still
 * owes money. Every discounted recurring order was created that way, because the discount
 * was applied when calculating the charge and never carried onto the order it paid for.
 */
class PaidOrderTotalsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> the order body we would have sent to Shopify */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The store's live rule ships as a data migration. These tests are about the
        // ARITHMETIC of the order, so they start from no rules and add one where the name
        // on the invoice is the thing under test.
        DiscountRule::query()->delete();
    }

    private function variant(string $id, float $price): ProductVariant
    {
        $product = Product::query()->create([
            'shopify_product_id' => 'p-'.$id,
            'title' => 'Food '.$id,
        ]);

        return ProductVariant::query()->create([
            'shopify_variant_id' => $id,
            'product_id' => $product->id,
            'title' => 'Variant '.$id,
            'price' => $price,
        ]);
    }

    private function scenario(float $charged, array $variantPrices = ['111' => 171.00]): array
    {
        foreach ($variantPrices as $id => $price) {
            $this->variant((string) $id, $price);
        }

        $customer = Customer::query()->create([
            'email' => 'paid@example.com',
            'shopify_customer_id' => '900700',
            'first_name' => 'גיל',
            'last_name' => 'לגזיאל',
            'phone' => '0504383830',
            'address1' => 'הנדיב 26',
            'address2' => 'קומה 2 דירה 3',
            'city' => 'הרצליה',
            'country' => 'Israel',
            'zip' => '4648540',
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
            'name' => 'Rex',
            'status' => 'active',
            'selected_variants' => array_map(
                fn ($id) => 'gid://shopify/ProductVariant/'.$id,
                array_keys($variantPrices),
            ),
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

        return [$subscription->fresh(), $ledger->fresh()];
    }

    private function createOrder(Subscription $subscription, PaymentLedger $ledger): void
    {
        $client = Mockery::mock(ShopifyAdminClient::class);
        $client->shouldReceive('isConnected')->andReturnTrue();
        $client->shouldReceive('restPost')->once()->andReturnUsing(function (string $path, array $body) {
            $this->sent = $body['order'] ?? [];

            return ['order' => ['id' => 555001, 'name' => '#1001']];
        });

        (new OrderCreationService($client))->createPaidOrder($subscription, $ledger);
    }

    public function test_a_discounted_order_is_created_paid_in_full_with_the_discount_named(): void
    {
        // ₪171.00 of product, ₪153.90 taken — the 10% that made every order partially paid.
        [$subscription, $ledger] = $this->scenario(charged: 153.90);

        $this->createOrder($subscription, $ledger);

        $this->assertSame('171.00', $this->sent['line_items'][0]['price']);

        // Stated on the order, so the customer reads WHY they paid less — and so the
        // arithmetic reconciles instead of leaving a balance outstanding.
        $this->assertSame('17.10', (string) $this->sent['discount_codes'][0]['amount']);
        $this->assertSame('fixed_amount', $this->sent['discount_codes'][0]['type']);
        $this->assertSame(__('subscriptions.discount_title'), $this->sent['discount_codes'][0]['code']);

        // What Shopify will compute: lines minus discount === the sale transaction.
        $lines = 171.00 * 1;
        $this->assertSame(
            round($lines - (float) $this->sent['discount_codes'][0]['amount'], 2),
            round((float) $this->sent['transactions'][0]['amount'], 2),
        );
    }

    public function test_the_order_carries_the_customer_delivery_address(): void
    {
        /*
         * Attaching the customer does NOT put an address on the order — Shopify copies
         * nothing from the customer record for an order created through the API. Without
         * this, every recurring order arrived unshippable: money taken, order recorded, and
         * nobody able to say where the box goes (orders 74411, 74412, 74457).
         */
        [$subscription, $ledger] = $this->scenario(charged: 171.00);

        $this->createOrder($subscription, $ledger);

        $this->assertSame('הנדיב 26', $this->sent['shipping_address']['address1']);
        $this->assertSame('קומה 2 דירה 3', $this->sent['shipping_address']['address2']);
        $this->assertSame('הרצליה', $this->sent['shipping_address']['city']);
        $this->assertSame('4648540', $this->sent['shipping_address']['zip']);
        $this->assertSame('0504383830', $this->sent['shipping_address']['phone']);
        $this->assertSame('גיל', $this->sent['shipping_address']['first_name']);

        // Nothing here ever collects a separate billing address, and an empty one makes the
        // order look half-filled in every export that reads it.
        $this->assertSame($this->sent['shipping_address'], $this->sent['billing_address']);
    }

    public function test_an_address_too_thin_to_ship_to_is_reported_rather_than_dressed_up(): void
    {
        /*
         * A street and a city are the minimum a courier can work with. The card is already
         * charged, so the order is still created — a missing order is worse than an
         * unshippable one — but it must not be created quietly.
         */
        [$subscription, $ledger] = $this->scenario(charged: 171.00);

        $subscription->customer->forceFill(['address1' => null])->save();

        $this->createOrder($subscription->fresh(), $ledger);

        $this->assertArrayNotHasKey('shipping_address', $this->sent);
        $this->assertArrayNotHasKey('billing_address', $this->sent);

        $this->assertDatabaseHas('system_logs', [
            'message' => 'order created with no deliverable address — nobody can ship it',
        ]);
    }

    public function test_the_rule_that_granted_the_discount_is_what_the_customer_reads(): void
    {
        /*
         * The merchant chose that wording for customers to see. A generic "Subscriber
         * discount" on an invoice for a named promotion tells the customer nothing about
         * why their price changed.
         */
        [$subscription, $ledger] = $this->scenario(charged: 153.90);

        DiscountRule::query()->create([
            'name' => 'מבצע פרימיום',
            'percent' => 10,
            'scope' => DiscountRule::SCOPE_ORDER,
        ]);

        $this->createOrder($subscription, $ledger);

        $this->assertSame('מבצע פרימיום', $this->sent['discount_codes'][0]['code']);
    }

    public function test_an_undiscounted_order_carries_no_discount_line(): void
    {
        [$subscription, $ledger] = $this->scenario(charged: 171.00);

        $this->createOrder($subscription, $ledger);

        $this->assertArrayNotHasKey('discount_codes', $this->sent);
        $this->assertSame('171.00', $this->sent['line_items'][0]['price']);
    }

    public function test_quantities_and_several_products_still_reconcile(): void
    {
        // Two foods, 10% off the pair: the gap must be taken off the ORDER, once.
        [$subscription, $ledger] = $this->scenario(
            charged: 234.00,               // (171 + 89) less 10%
            variantPrices: ['111' => 171.00, '222' => 89.00],
        );

        $this->createOrder($subscription, $ledger);

        $this->assertSame('26.00', (string) $this->sent['discount_codes'][0]['amount']);
    }

    public function test_a_variant_missing_from_the_cache_leaves_the_order_exactly_as_before(): void
    {
        /*
         * We cannot do the sum honestly without a price, and forcing a match with a guessed
         * number would put a fabricated discount on a customer's invoice. Create the order
         * as before and let the log say why it could not be reconciled.
         */
        [$subscription, $ledger] = $this->scenario(charged: 153.90);
        ProductVariant::query()->where('shopify_variant_id', '111')->delete();

        $this->createOrder($subscription, $ledger);

        $this->assertArrayNotHasKey('discount_codes', $this->sent);
        $this->assertArrayNotHasKey('price', $this->sent['line_items'][0]);
    }

    public function test_a_charge_larger_than_the_lines_never_becomes_a_negative_discount(): void
    {
        // A negative discount is a lie on an invoice; leave it for a person to look at.
        [$subscription, $ledger] = $this->scenario(charged: 200.00);

        $this->createOrder($subscription, $ledger);

        $this->assertArrayNotHasKey('discount_codes', $this->sent);
    }
}
