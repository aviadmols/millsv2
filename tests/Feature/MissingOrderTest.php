<?php

namespace Tests\Feature;

use App\Filament\Widgets\MissingOrders;
use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\PaymentLedger;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\User;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Services\Shopify\OrderCreationService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use App\Modules\MillsSubscriptions\Support\ShopifyErrors;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * A customer who paid and got no order.
 *
 * Order creation never unwinds a charge — the money has moved — and that made its failure
 * silent. Subscription 321 was charged ₪414, Shopify refused the order, and the only trace
 * was a system-log row found four days later. These tests hold the three things that fix
 * that: the reason is kept on the charge, it is in Shopify's own words, and the home screen
 * shows it without anyone having to go looking.
 */
class MissingOrderTest extends TestCase
{
    use RefreshDatabase;

    private function charge(array $overrides = []): PaymentLedger
    {
        $customer = Customer::query()->create([
            'email' => uniqid('m', true).'@x.co',
            'shopify_customer_id' => (string) random_int(1000, 999999),
            'first_name' => 'נועה', 'address1' => 'הרצל 1', 'city' => 'תל אביב',
        ]);

        $subscription = new Subscription;
        $subscription->fill(['customer_id' => $customer->id, 'payment_state' => PaymentState::PAYME->value, 'frequency_months' => 1]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $row = PaymentLedger::query()->create(array_merge([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'context' => 'recurring',
            'idempotency_key' => uniqid('k', true),
            'amount' => 414.00,
            'currency' => 'ILS',
            'executed_at' => now()->subHour(),
        ], $overrides));
        $row->forceFill(['status' => $overrides['status'] ?? LedgerStatus::SUCCEEDED->value])->save();

        return $row->fresh();
    }

    private function withProduct(PaymentLedger $ledger): PaymentLedger
    {
        $product = Product::query()->firstOrCreate(['shopify_product_id' => 'p-1'], ['title' => 'Food']);
        ProductVariant::query()->firstOrCreate(['shopify_variant_id' => '111'], ['product_id' => $product->id, 'title' => '30', 'price' => 414.00]);

        Dog::query()->create([
            'customer_id' => $ledger->customer_id,
            'subscription_id' => $ledger->subscription_id,
            'name' => 'Rex', 'status' => 'active',
            'selected_variants' => ['gid://shopify/ProductVariant/111'],
            'addons_products' => [],
        ]);

        return $ledger->fresh();
    }

    // --- the reason, in Shopify's words ----------------------------------------

    public function test_shopify_rest_errors_read_as_the_field_it_objected_to(): void
    {
        $this->assertSame(
            'shipping_address: country is not valid',
            ShopifyErrors::describe(['shipping_address' => ['country is not valid']]),
        );
    }

    public function test_shopify_graphql_user_errors_read_as_the_field_it_objected_to(): void
    {
        // The "input" wrapper names nothing; the field after it is what the admin can fix.
        $this->assertSame(
            'shippingLine.price: Price must be positive',
            ShopifyErrors::describe([['field' => ['input', 'shippingLine', 'price'], 'message' => 'Price must be positive']]),
        );
    }

    public function test_a_refused_draft_tells_the_admin_why_instead_of_a_code(): void
    {
        // Subscription 324: the screen said "shopify_draft_order_failed" and nothing more.
        $ledger = $this->withProduct($this->charge());

        $client = Mockery::mock(ShopifyAdminClient::class);
        $client->shouldReceive('isConnected')->andReturnTrue();
        $client->shouldReceive('graphql')->andReturn(['data' => ['draftOrderCreate' => [
            'draftOrder' => null,
            'userErrors' => [['field' => ['input', 'purchasingEntity'], 'message' => 'Customer does not exist']],
        ]]]);

        try {
            (new DraftOrderService($client))->create($ledger->subscription);
            $this->fail('a refused draft must throw');
        } catch (RuntimeException $e) {
            $this->assertSame('purchasingEntity: Customer does not exist', $e->getMessage());
            $this->assertStringNotContainsString('shopify_draft_order_failed', $e->getMessage());
        }
    }

    public function test_rebuilding_over_a_draft_that_no_longer_exists_creates_a_fresh_one(): void
    {
        // Subscription 324: its stored draft had already been completed/deleted in Shopify,
        // so the delete step of the rebuild answered "Draft order not found" — and that
        // reached the screen instead of the new draft being built. A draft that is gone is
        // exactly the case the rebuild exists for.
        $ledger = $this->withProduct($this->charge());
        $subscription = $ledger->subscription;
        $subscription->forceFill(['draft_order_id' => '1111'])->save();

        $client = Mockery::mock(ShopifyAdminClient::class);
        $client->shouldReceive('isConnected')->andReturnTrue();
        $client->shouldReceive('graphql')
            ->withArgs(fn (string $query) => str_contains($query, 'draftOrderDelete'))
            ->once()
            ->andReturn(['data' => ['draftOrderDelete' => [
                'deletedId' => null,
                'userErrors' => [['field' => ['id'], 'message' => 'Draft order not found']],
            ]]]);
        $client->shouldReceive('graphql')
            ->withArgs(fn (string $query) => str_contains($query, 'draftOrderCreate'))
            ->once()
            ->andReturn(['data' => ['draftOrderCreate' => [
                'draftOrder' => [
                    'id' => 'gid://shopify/DraftOrder/2222',
                    'name' => '#D2',
                    'status' => 'OPEN',
                    'totalPriceSet' => ['shopMoney' => ['amount' => '414.00', 'currencyCode' => 'ILS']],
                    'subtotalPriceSet' => ['shopMoney' => ['amount' => '414.00', 'currencyCode' => 'ILS']],
                    'lineItems' => ['nodes' => []],
                ],
                'userErrors' => [],
            ]]]);

        $draft = (new DraftOrderService($client))->refresh($subscription);

        $this->assertSame('2222', (string) $draft['id']);
        $this->assertSame('2222', (string) $subscription->fresh()->draft_order_id);
    }

    // --- the reason is kept on the charge -------------------------------------

    public function test_a_refused_order_writes_shopifys_reason_onto_the_charge(): void
    {
        $ledger = $this->withProduct($this->charge());

        $client = Mockery::mock(ShopifyAdminClient::class);
        $client->shouldReceive('isConnected')->andReturnTrue();
        $client->shouldReceive('restPost')->andReturn(['errors' => ['shipping_address' => ['country is not valid']]]);

        $result = (new OrderCreationService($client))->createPaidOrder($ledger->subscription, $ledger);

        $this->assertNull($result);

        $ledger = $ledger->fresh();
        $this->assertSame('shipping_address: country is not valid', $ledger->order_error);
        $this->assertNotNull($ledger->order_attempted_at);

        // And the charge itself is untouched — money that moved is never unwound here.
        $this->assertSame(LedgerStatus::SUCCEEDED, $ledger->status);
    }

    public function test_a_subscription_with_no_products_records_that_as_the_reason(): void
    {
        $ledger = $this->charge();   // no dog, no products

        $client = Mockery::mock(ShopifyAdminClient::class);
        $client->shouldReceive('isConnected')->andReturnTrue();

        (new OrderCreationService($client))->createPaidOrder($ledger->subscription, $ledger);

        $this->assertSame(__('ledgers.order_error_no_products'), $ledger->fresh()->order_error);
    }

    public function test_an_order_that_finally_goes_through_stops_being_reported_missing(): void
    {
        $ledger = $this->withProduct($this->charge(['order_error' => 'an earlier failure']));

        $client = Mockery::mock(ShopifyAdminClient::class);
        $client->shouldReceive('isConnected')->andReturnTrue();
        $client->shouldReceive('restPost')->andReturn(['order' => ['id' => 555, 'name' => '#1']]);

        (new OrderCreationService($client))->createPaidOrder($ledger->subscription, $ledger);

        $ledger = $ledger->fresh();
        $this->assertSame('555', $ledger->shopify_order_id);
        $this->assertNull($ledger->order_error);
        $this->assertFalse($ledger->isMissingOrder());
    }

    // --- what counts as missing -----------------------------------------------

    public function test_only_a_real_paid_charge_without_an_order_counts_as_missing(): void
    {
        $missing = $this->charge();

        $hasOrder = $this->charge(['shopify_order_id' => '999']);
        $failed = $this->charge(['status' => LedgerStatus::FAILED->value]);
        $cardCheck = $this->charge(['context' => 'card_update']);
        $v1 = $this->charge(['context' => 'v1_import']);
        $justNow = $this->charge(['executed_at' => now()->subMinutes(2)]);   // still creating its order

        $this->assertTrue($missing->isMissingOrder());

        foreach ([$hasOrder, $failed, $cardCheck, $v1, $justNow] as $row) {
            $this->assertFalse($row->isMissingOrder());
        }

        // The query agrees with the method, row for row.
        $this->assertSame([$missing->id], PaymentLedger::query()->missingOrder()->pluck('id')->all());
    }

    // --- the home screen says so ------------------------------------------------

    public function test_the_home_screen_shows_who_paid_and_has_no_order_with_the_reason(): void
    {
        $this->actingAs(User::factory()->create());

        $this->charge(['order_error' => 'shipping_address: country is not valid']);

        $this->assertTrue(MissingOrders::canView());

        Livewire::test(MissingOrders::class)
            ->assertSee(__('dashboard.missing_orders_heading'))
            ->assertSee('shipping_address: country is not valid')
            ->assertSee('₪414.00')
            ->assertSee(__('dashboard.missing_orders_resolve'));
    }

    public function test_a_charge_from_before_reasons_were_recorded_still_appears(): void
    {
        // Subscription 321 itself: it predates the column, so it has no recorded reason —
        // and it must still be on the screen, pointing at where the reason can be found.
        $this->actingAs(User::factory()->create());
        $this->charge();

        Livewire::test(MissingOrders::class)
            ->assertSee(__('ledgers.order_error_unrecorded'));
    }

    // --- once it is dealt with, it leaves ---------------------------------------

    public function test_resolving_takes_the_customer_off_the_alert_and_leaves_the_money_alone(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $ledger = $this->charge();

        Livewire::test(MissingOrders::class)
            ->callAction('markResolved', data: ['note' => 'נוצרה הזמנה ידנית'], arguments: ['ledger' => $ledger->id])
            ->assertHasNoActionErrors()
            ->assertSee(__('dashboard.missing_orders_all_resolved'));

        $ledger = $ledger->fresh();
        $this->assertFalse($ledger->isMissingOrder());
        $this->assertTrue($ledger->isOrderResolvedByHand());
        $this->assertSame('נוצרה הזמנה ידנית', $ledger->order_resolved_note);
        $this->assertSame(Timeline::admin($admin->id), $ledger->order_resolved_by);
        $this->assertFalse(MissingOrders::canView());

        // The charge is exactly what it was — resolving is bookkeeping, not money.
        $this->assertSame(LedgerStatus::SUCCEEDED, $ledger->status);
        $this->assertSame('414.00', (string) $ledger->amount);

        // And it is on the subscription's history, with who did it.
        $event = ActivityEvent::query()->where('kind', Timeline::KIND_ADMIN_NOTE)->sole();
        $this->assertSame($ledger->subscription_id, $event->subscription_id);
        $this->assertSame(Timeline::admin($admin->id), $event->actor);
        $this->assertStringContainsString('נוצרה הזמנה ידנית', $event->details['note']);
    }

    public function test_the_order_created_by_hand_is_linked_from_its_shopify_address(): void
    {
        $this->actingAs(User::factory()->create());
        $ledger = $this->charge();

        Livewire::test(MissingOrders::class)
            ->callAction('markResolved', data: [
                'order' => 'https://admin.shopify.com/store/millsforpets/orders/19030456926512',
            ], arguments: ['ledger' => $ledger->id])
            ->assertHasNoActionErrors();

        $this->assertSame('19030456926512', $ledger->fresh()->shopify_order_id);
    }

    public function test_an_order_number_is_refused_because_shopify_cannot_be_addressed_by_it(): void
    {
        $this->actingAs(User::factory()->create());
        $ledger = $this->charge();

        Livewire::test(MissingOrders::class)
            ->callAction('markResolved', data: ['order' => '#74500'], arguments: ['ledger' => $ledger->id])
            ->assertHasActionErrors(['order']);

        // Nothing written: the customer is still on the alert.
        $this->assertTrue($ledger->fresh()->isMissingOrder());
    }

    public function test_the_shopify_order_id_is_read_from_what_an_admin_would_paste(): void
    {
        $this->assertSame('19030456926512', PaymentLedger::shopifyOrderIdFrom('https://admin.shopify.com/store/millsforpets/orders/19030456926512'));
        $this->assertSame('19030456926512', PaymentLedger::shopifyOrderIdFrom('https://admin.shopify.com/store/millsforpets/orders/19030456926512?tab=x'));
        $this->assertSame('19030456926512', PaymentLedger::shopifyOrderIdFrom(' 19030456926512 '));
        $this->assertSame('19030456926512', PaymentLedger::shopifyOrderIdFrom('gid://shopify/Order/19030456926512'));
        $this->assertNull(PaymentLedger::shopifyOrderIdFrom('#74500'));
        $this->assertNull(PaymentLedger::shopifyOrderIdFrom('74500'));
        $this->assertNull(PaymentLedger::shopifyOrderIdFrom(''));
    }

    public function test_the_alert_is_absent_when_nobody_is_missing_an_order(): void
    {
        $this->charge(['shopify_order_id' => '999']);

        $this->assertFalse(MissingOrders::canView());
    }
}
