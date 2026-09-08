<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Services\RefundService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use App\Modules\MillsSubscriptions\Support\Timeline;
use App\Support\Ui\EventPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Giving money back — for real.
 *
 * Refunding in Shopify does nothing to the card, because the charge never went through
 * Shopify. This is the path that actually moves the money, and every rule here is about
 * money: PayMe must confirm before anything is written down, nothing may be refunded that
 * was never taken, and nothing may be refunded twice.
 */
class RefundTest extends TestCase
{
    use RefreshDatabase;

    private function charge(float $amount = 171.00, LedgerStatus $status = LedgerStatus::SUCCEEDED, ?string $saleId = 'SALE-1'): PaymentLedger
    {
        $customer = Customer::query()->create([
            'email' => uniqid('r', true).'@x.co',
            'shopify_customer_id' => (string) random_int(1000, 999999),
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        $row = PaymentLedger::query()->create([
            'subscription_id' => $subscription->id,
            'customer_id' => $customer->id,
            'context' => 'recurring',
            'idempotency_key' => uniqid('k', true),
            'amount' => $amount,
            'currency' => 'ILS',
            'executed_at' => now(),
            'payme_transaction_id' => $saleId,
            'shopify_order_id' => '19019226579248',
        ]);
        $row->forceFill(['status' => $status->value])->save();

        return $row->fresh();
    }

    /** @var list<array<string, mixed>> every call the mocked gateways received, in order */
    private array $calls = [];

    private function service(array $paymeAnswer = ['status_code' => 0], bool $shopifyConnected = true): RefundService
    {
        $this->calls = [];

        $payme = Mockery::mock(PaymeClient::class);
        $payme->shouldReceive('refundSale')->andReturnUsing(function (string $saleId, ?int $agorot) use ($paymeAnswer) {
            $this->calls[] = ['sale' => $saleId, 'agorot' => $agorot];

            return $paymeAnswer;
        });

        $shopify = Mockery::mock(ShopifyAdminClient::class);
        $shopify->shouldReceive('isConnected')->andReturn($shopifyConnected);
        $shopify->shouldReceive('restGet')->andReturn(['transactions' => [
            ['id' => 777, 'kind' => 'sale', 'status' => 'success', 'gateway' => 'manual'],
        ]]);
        $shopify->shouldReceive('restPost')->andReturnUsing(function (string $path, array $body) {
            $this->calls[] = ['shopify' => $path, 'body' => $body];

            return ['refund' => ['id' => 1]];
        });

        return new RefundService($payme, $shopify);
    }

    public function test_a_full_refund_moves_the_money_then_writes_it_down_everywhere(): void
    {
        $row = $this->charge(171.00);
        $service = $this->service();

        $result = $service->refund($row, null, Timeline::admin(7), 'לקוח ביטל לפני משלוח');

        $calls = $this->calls;

        // 1. PayMe, in agorot, against the sale id we stored when we took the money.
        $this->assertSame('SALE-1', $calls[0]['sale']);
        $this->assertSame(17100, $calls[0]['agorot']);

        // 2. The ledger.
        $row = $row->fresh();
        $this->assertSame(LedgerStatus::REFUNDED, $row->status);
        $this->assertSame('171.00', (string) $row->refunded_amount);
        $this->assertNotNull($row->refunded_at);

        // 3. Shopify, hung on the original sale transaction so the order reconciles.
        $shopifyCall = collect($calls)->first(fn ($c) => isset($c['shopify']));
        $this->assertSame('orders/19019226579248/refunds.json', $shopifyCall['shopify']);
        $this->assertSame(777, $shopifyCall['body']['refund']['transactions'][0]['parent_id']);
        $this->assertSame('171.00', $shopifyCall['body']['refund']['transactions'][0]['amount']);

        $this->assertTrue($result['full']);

        // And the timeline says who, how much, and why — in words.
        $event = ActivityEvent::query()->where('kind', Timeline::KIND_CHARGE_REFUNDED)->firstOrFail();
        $this->assertSame('admin:7', $event->actor);
        $this->assertStringContainsString('₪171.00', EventPresenter::summarize($event));
        $this->assertStringContainsString('לקוח ביטל לפני משלוח', EventPresenter::summarize($event));
    }

    public function test_a_partial_refund_leaves_the_charge_succeeded_with_the_amount_beside_it(): void
    {
        // The status machine has one refund state and no "partly": a partial refund must
        // not turn the row REFUNDED, or the remainder becomes un-refundable.
        $row = $this->charge(171.00);
        $service = $this->service();

        $result = $service->refund($row, 50.00, Timeline::admin(7));

        $row = $row->fresh();
        $this->assertFalse($result['full']);
        $this->assertSame(LedgerStatus::SUCCEEDED, $row->status);
        $this->assertSame('50.00', (string) $row->refunded_amount);

        // The rest can still go back — and the row flips only once all of it has.
        $service->refund($row, 121.00, Timeline::admin(7));
        $this->assertSame(LedgerStatus::REFUNDED, $row->fresh()->status);
    }

    public function test_payme_must_say_yes_before_anything_is_written_down(): void
    {
        // If the money did not move, the books must not say it did — that is a second
        // refund waiting to be issued.
        $row = $this->charge(171.00);
        $service = $this->service(['status_code' => 1, 'status_error_details' => 'insufficient balance']);

        try {
            $service->refund($row, null, Timeline::admin(7));
            $this->fail('a refused refund must throw');
        } catch (RuntimeException $e) {
            $this->assertSame('refund_payme_refused', $e->getMessage());
        }

        $row = $row->fresh();
        $this->assertSame(LedgerStatus::SUCCEEDED, $row->status);
        $this->assertNull($row->refunded_amount);
        $this->assertSame(0, ActivityEvent::query()->where('kind', Timeline::KIND_CHARGE_REFUNDED)->count());
    }

    public function test_nothing_can_be_refunded_that_was_never_taken(): void
    {
        $service = $this->service();

        foreach ([LedgerStatus::PENDING, LedgerStatus::FAILED, LedgerStatus::REFUNDED] as $status) {
            $row = $this->charge(171.00, $status);

            try {
                $service->refund($row, null, Timeline::admin(7));
                $this->fail("a {$status->value} charge must not be refundable");
            } catch (RuntimeException $e) {
                $this->assertSame('refund_not_succeeded', $e->getMessage());
            }
        }
    }

    public function test_the_refund_cannot_exceed_what_is_still_outstanding(): void
    {
        $row = $this->charge(171.00);
        $service = $this->service();

        $service->refund($row, 100.00, Timeline::admin(7));

        try {
            $service->refund($row->fresh(), 100.00, Timeline::admin(7));   // only 71 left
            $this->fail('over-refunding must throw');
        } catch (RuntimeException $e) {
            $this->assertSame('refund_amount_out_of_range', $e->getMessage());
        }
    }

    public function test_a_charge_with_no_payme_sale_id_says_so_instead_of_guessing(): void
    {
        $row = $this->charge(171.00, LedgerStatus::SUCCEEDED, saleId: null);
        $service = $this->service();

        $this->expectExceptionMessage('refund_no_sale_id');
        $service->refund($row, null, Timeline::admin(7));
    }

    public function test_a_shopify_failure_never_undoes_a_refund_payme_already_made(): void
    {
        // The money has moved. A failure to write it in Shopify is repairable; pretending
        // the refund did not happen is not.
        $row = $this->charge(171.00);

        $payme = Mockery::mock(PaymeClient::class);
        $payme->shouldReceive('refundSale')->andReturn(['status_code' => 0]);

        $shopify = Mockery::mock(ShopifyAdminClient::class);
        $shopify->shouldReceive('isConnected')->andReturnTrue();
        $shopify->shouldReceive('restGet')->andThrow(new RuntimeException('Shopify is down'));

        $result = (new RefundService($payme, $shopify))->refund($row, null, Timeline::admin(7));

        $this->assertTrue($result['full']);
        $this->assertSame(LedgerStatus::REFUNDED, $row->fresh()->status);
        $this->assertDatabaseHas('system_logs', [
            'message' => 'refund done in PayMe but could not be recorded on the Shopify order',
        ]);
    }
}
