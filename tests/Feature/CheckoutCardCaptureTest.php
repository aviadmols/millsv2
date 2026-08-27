<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\ShopifyConnection;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\PaidOrderIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A customer who paid for a subscription at checkout has ALREADY given PayMe their card.
 *
 * v1 pulled the reusable buyer_key straight out of that payment (order → transaction →
 * get-transactions → get-buyer-key); v2 started every ingested subscription behind the
 * card-update wall instead, which greeted a paying customer with "עדכן כרטיס אשראי"
 * seconds after they typed the card. These tests pin the v1 behaviour: the wall lifts
 * from the checkout itself, and only genuine failure leaves it standing — retried by
 * the sweep, never dumped on the customer.
 */
class CheckoutCardCaptureTest extends TestCase
{
    use RefreshDatabase;

    private const SUBS_PRODUCT_ID = '9991874183472';

    private const PAYMENT_ID = 'pay_772211';

    private const SALE_ID = 'SALE1787-CHECKOUT-1';

    private const BUYER_KEY = 'BUYER173-FROM-CHECKOUT';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('payme.api_url', 'https://payme.test');
        config()->set('payme.seller_id', 'SELLER1');

        ShopifyConnection::query()->create([
            'shop_domain' => 'millsforpets.myshopify.com',
            'access_token' => 'shpat_test',
            'installed_at' => now(),
        ]);

        Product::query()->create([
            'shopify_product_id' => self::SUBS_PRODUCT_ID,
            'title' => 'מנוי מתחדש',
            'handle' => 'subs-test',
            'status' => 'active',
        ]);
    }

    /** @return array<string, mixed> */
    private function paidOrder(): array
    {
        return [
            'id' => 18927529197872,
            'name' => '#90055',
            'processed_at' => '2026-08-27T09:30:00+03:00',
            'customer' => [
                'id' => 8537351520560,
                'email' => 'buyer@example.com',
                'first_name' => 'אביעד',
            ],
            'line_items' => [[
                'product_id' => (int) self::SUBS_PRODUCT_ID,
                'variant_id' => 66514429149488,
                'sku' => 'TB30 - אריזה יומית של 79 גרם',
                'quantity' => 1,
                'price' => '162.00',
                'properties' => [['name' => '_subscription', 'value' => 'true']],
            ]],
        ];
    }

    private function fakeHappyPath(): void
    {
        Http::fake([
            // Shopify: the order's successful transaction carries PayMe's payment id.
            '*/graphql.json' => Http::response(['data' => ['order' => ['transactions' => [
                ['status' => 'SUCCESS', 'kind' => 'SALE', 'paymentId' => self::PAYMENT_ID],
            ]]]]),
            'https://payme.test/get-transactions' => Http::response([
                'status_code' => 0,
                'items' => [['sale_payme_id' => self::SALE_ID]],
            ]),
            'https://payme.test/get-buyer-key' => Http::response([
                'status_code' => 0,
                'buyer_key' => self::BUYER_KEY,
                'card_mask' => '458045******4580',
            ]),
        ]);
    }

    public function test_a_paid_checkout_arrives_with_its_card_already_captured(): void
    {
        $this->fakeHappyPath();

        $subscription = app(PaidOrderIngestor::class)->ingest($this->paidOrder());

        // The whole point: no wall, no "עדכן כרטיס" for a customer who just paid —
        // and ACTIVE, because the dispatcher only ever charges ACTIVE. A captured
        // card on a PENDING subscription is billable in every respect except the
        // one the biller reads.
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->fresh()->status);

        $method = Customer::query()->firstOrFail()->activePaymentMethod();
        $this->assertSame(self::BUYER_KEY, $method->buyer_key);
        $this->assertSame('458045******4580', $method->masked_card);
    }

    public function test_payme_refusing_leaves_the_wall_up_and_the_subscription_intact(): void
    {
        Http::fake([
            '*/graphql.json' => Http::response(['data' => ['order' => ['transactions' => [
                ['status' => 'SUCCESS', 'kind' => 'SALE', 'paymentId' => self::PAYMENT_ID],
            ]]]]),
            'https://payme.test/get-transactions' => Http::response([
                'status_code' => 0,
                'items' => [['sale_payme_id' => self::SALE_ID]],
            ]),
            'https://payme.test/get-buyer-key' => Http::response([
                'status_code' => 1,
                'status_error_details' => 'Merchant not allowed to use this buyer',
            ], 500),
        ]);

        $subscription = app(PaidOrderIngestor::class)->ingest($this->paidOrder());

        // The capture failing must never fail the ingestion — the subscription exists,
        // walled, and the reason is on the admin's screen.
        $this->assertNotNull($subscription);
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->fresh()->payment_state);
        $this->assertDatabaseHas('system_logs', [
            'message' => 'could not capture the card from the checkout payment',
        ]);
    }

    public function test_the_sweep_heals_a_subscription_that_was_ingested_before_this_code_shipped(): void
    {
        Http::fake([
            // First pass: Shopify has no transaction data yet → walled subscription (the
            // state the 27 Aug order is in). Second pass: the transaction is there.
            '*/graphql.json' => Http::sequence()
                ->push(['data' => ['order' => ['transactions' => []]]])
                ->push(['data' => ['order' => ['transactions' => [
                    ['status' => 'SUCCESS', 'kind' => 'SALE', 'paymentId' => self::PAYMENT_ID],
                ]]]]),
            'https://payme.test/get-transactions' => Http::response([
                'status_code' => 0,
                'items' => [['sale_payme_id' => self::SALE_ID]],
            ]),
            'https://payme.test/get-buyer-key' => Http::response([
                'status_code' => 0,
                'buyer_key' => self::BUYER_KEY,
            ]),
        ]);

        $subscription = app(PaidOrderIngestor::class)->ingest($this->paidOrder());
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->fresh()->payment_state);

        // Next sweep pass: same order re-read, wall comes down — the customer did
        // nothing in between.
        $again = app(PaidOrderIngestor::class)->ingest($this->paidOrder());

        $this->assertSame($subscription->id, $again->id);
        $this->assertSame(1, Subscription::query()->count());
        $this->assertSame(PaymentState::PAYME, $subscription->fresh()->payment_state);
        // Healed all the way: not just unwalled but ACTIVE — ready for the next cycle.
        $this->assertSame(SubscriptionStatus::ACTIVE, $subscription->fresh()->status);
    }

    public function test_a_paypal_order_says_so_instead_of_blaming_payme(): void
    {
        /*
         * PayPal leaves no reusable token, and blocking it at checkout needs a Shopify
         * Function — which for a custom app needs Shopify Plus, which this store is not
         * on. So these orders keep arriving, and the log has to name the reason rather
         * than reporting "PayMe returned no sale", which reads like a PayMe outage.
         */
        Http::fake([
            '*/graphql.json' => Http::response(['data' => ['order' => ['transactions' => [
                ['status' => 'SUCCESS', 'kind' => 'SALE', 'paymentId' => 'pp-123', 'gateway' => 'paypal'],
            ]]]]),
        ]);

        $subscription = app(PaidOrderIngestor::class)->ingest($this->paidOrder());

        $this->assertNotNull($subscription);
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $subscription->fresh()->payment_state);

        $log = SystemLog::query()
            ->where('message', 'could not capture the card from the checkout payment')
            ->firstOrFail();

        $this->assertStringContainsString('paypal', $log->context['message'] ?? '');

        // And PayMe was never asked about a payment that was never theirs.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'payme.test'));
    }

    public function test_a_card_the_customer_chose_later_is_never_overwritten_by_an_old_order(): void
    {
        $this->fakeHappyPath();
        $subscription = app(PaidOrderIngestor::class)->ingest($this->paidOrder());

        // The customer updates their card in the portal — a DIFFERENT, newer credential.
        $customer = $subscription->customer;
        $customer->paymentMethods()->update(['is_active' => false]);
        $customer->paymentMethods()->create([
            'gateway' => 'payme',
            'buyer_key' => 'BUYER-NEWER-CHOICE',
            'is_active' => true,
            'source' => 'card_update',
            'captured_at' => now(),
        ]);
        $subscription->forceFill(['payment_state' => PaymentState::NEEDS_CARD_UPDATE->value])->save();

        app(PaidOrderIngestor::class)->ingest($this->paidOrder());

        // The checkout capture must defer: their chosen card stays the active one.
        $this->assertSame('BUYER-NEWER-CHOICE', $customer->fresh()->activePaymentMethod()->buyer_key);
    }
}
