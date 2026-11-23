<?php

namespace Tests\Feature;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayResult;
use App\Models\Customer;
use App\Models\Dog;
use App\Models\PaymentLedger;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\ChargeOrchestrator;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the billing engine (ARCHITECTURE.md §5): success advances the cycle and
 * writes the ledger; the same key never double-charges; failure schedules the
 * backoff and exhausts to past_due; a missing card fails closed to
 * needs_card_update — all without touching a real PayMe.
 */
class ChargeOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private function orchestrator(bool $succeed, string $txId = 'sale_1'): ChargeOrchestrator
    {
        $gateway = new class($succeed, $txId) implements PaymentGateway
        {
            public function __construct(private bool $succeed, private string $txId) {}

            public function chargeWithReference(string $reference, int $amountAgorot, string $idempotencyKey, array $opts = []): GatewayResult
            {
                return $this->succeed
                    ? GatewayResult::success($this->txId, ['payme_status_code' => 0])
                    : GatewayResult::failure('declined', 'Card declined', ['payme_status_code' => 5]);
            }

            public function lookup(string $idempotencyKey): GatewayResult
            {
                return $this->succeed
                    ? GatewayResult::success($this->txId)
                    : GatewayResult::failure('declined', 'Card declined');
            }
        };

        return new ChargeOrchestrator($gateway);
    }

    private function subscription(array $overrides = []): Subscription
    {
        $customer = Customer::query()->create(['email' => uniqid('c_', true).'@x.com', 'shopify_customer_id' => (string) random_int(1, 9_999_999)]);
        PaymentMethod::query()->create([
            'customer_id' => $customer->id,
            'gateway' => 'payme',
            'buyer_key' => 'BUYER-KEY-XYZ',
            'is_active' => true,
            'captured_at' => now(),
        ]);

        $sub = new Subscription(array_merge([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->subDay(),
            'meta' => ['price' => 153.90],
        ], $overrides));
        $sub->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $sub->refresh();
    }

    public function test_successful_charge_writes_ledger_and_advances_the_cycle(): void
    {
        $sub = $this->subscription();
        $due = $sub->next_charge_at->copy();

        $result = $this->orchestrator(succeed: true)->charge($sub);

        $this->assertTrue($result['success']);
        $sub->refresh();

        $ledger = PaymentLedger::query()->where('subscription_id', $sub->id)->firstOrFail();
        $this->assertSame(LedgerStatus::SUCCEEDED, $ledger->status);
        $this->assertSame('sale_1', $ledger->payme_transaction_id);
        $this->assertSame(0, $sub->attempt_count);
        $this->assertSame($due->addMonthNoOverflow()->toDateString(), $sub->next_charge_at->toDateString());
        $this->assertDatabaseHas('activity_events', ['subscription_id' => $sub->id, 'kind' => 'charge_succeeded']);
    }

    public function test_same_idempotency_key_never_double_charges(): void
    {
        $sub = $this->subscription();
        $key = 'recurring:'.$sub->id.':2026-06-01';

        $this->orchestrator(succeed: true)->charge($sub, idempotencyKey: $key);
        $second = $this->orchestrator(succeed: true)->charge($sub->refresh(), idempotencyKey: $key);

        $this->assertSame('already_charged', $second['status']);
        $this->assertSame(1, PaymentLedger::query()->where('idempotency_key', $key)->count());
    }

    public function test_failed_charge_schedules_the_backoff(): void
    {
        $sub = $this->subscription();

        $result = $this->orchestrator(succeed: false)->charge($sub);
        $sub->refresh();

        $this->assertFalse($result['success']);
        $this->assertSame(1, $sub->attempt_count);
        $this->assertNotNull($sub->next_retry_at);
        $this->assertSame(SubscriptionStatus::ACTIVE, $sub->status); // still active, will retry
        $this->assertSame(LedgerStatus::RETRY_SCHEDULED, PaymentLedger::query()->where('subscription_id', $sub->id)->firstOrFail()->status);
    }

    public function test_exhausted_retries_move_subscription_to_past_due(): void
    {
        $sub = $this->subscription();
        $sub->forceFill(['attempt_count' => 3])->save(); // [4,24,72] exhausted

        $this->orchestrator(succeed: false)->charge($sub->refresh(), idempotencyKey: 'recurring:'.$sub->id.':final');

        $this->assertSame(SubscriptionStatus::PAST_DUE, $sub->refresh()->status);
    }

    public function test_missing_payment_method_fails_closed_to_needs_card_update(): void
    {
        $sub = $this->subscription();
        $sub->customer->paymentMethods()->update(['is_active' => false]);

        $result = $this->orchestrator(succeed: true)->charge($sub);

        $this->assertSame('needs_card_update', $result['status']);
        $this->assertSame(PaymentState::NEEDS_CARD_UPDATE, $sub->refresh()->payment_state);
        $this->assertSame(0, PaymentLedger::query()->count());
    }

    /** One dog on one ₪171 bag, with whatever amount is stored for the next charge. */
    private function subscriptionWithOneBag(float $storedAmount): Subscription
    {
        $product = Product::query()->create(['shopify_product_id' => 'p-171', 'title' => 'Food']);
        ProductVariant::query()->create(['shopify_variant_id' => '39357390782621', 'product_id' => $product->id, 'title' => '3kg', 'price' => 171.00]);

        $sub = $this->subscription(['next_charge_amount' => $storedAmount, 'meta' => null]);
        Dog::withoutEvents(fn () => Dog::query()->create([
            'customer_id' => $sub->customer_id,
            'subscription_id' => $sub->id,
            'status' => 'active',
            'selected_variants' => ['39357390782621'],
        ]));

        return $sub->refresh();
    }

    public function test_a_stored_amount_higher_than_the_order_is_never_charged(): void
    {
        // Subscription 921: ₪342 stored, one ₪171 bag on the dog — charged anyway (#76177).
        $sub = $this->subscriptionWithOneBag(342.00);

        $result = $this->orchestrator(succeed: true)->charge($sub);

        $this->assertFalse($result['success']);
        $this->assertSame('amount_exceeds_order', $result['status']);
        $this->assertSame(0, PaymentLedger::query()->count());
        $this->assertDatabaseHas('system_logs', ['subscription_id' => $sub->id, 'level' => 'error']);
    }

    public function test_an_amount_matching_the_discounted_order_still_charges(): void
    {
        $sub = $this->subscriptionWithOneBag(153.90); // the bag, after the 10% subscription rule

        $this->assertTrue($this->orchestrator(succeed: true)->charge($sub)['success']);
    }

    /** A DraftOrderService that either rebuilds to the live total or fails like Shopify did. */
    private function fakeDraftRebuild(?float $rebuiltTotal): void
    {
        $this->app->instance(DraftOrderService::class, new class($rebuiltTotal) extends DraftOrderService
        {
            public int $calls = 0;

            public function __construct(private ?float $total) {}

            public function refresh(Subscription $subscription): array
            {
                $this->calls++;

                if ($this->total === null) {
                    throw new \RuntimeException('email: Email contains an invalid domain name');
                }

                $subscription->forceFill(['next_charge_amount' => $this->total, 'next_charge_amount_at' => now()])->save();

                return ['total' => $this->total];
            }
        });
    }

    public function test_a_stale_lower_amount_is_rebuilt_and_the_real_order_total_is_charged(): void
    {
        // Subscription 971: a second dog added, the old one-dog total charged (#78786).
        $sub = $this->subscriptionWithOneBag(100.00);
        $this->fakeDraftRebuild(153.90);

        $result = $this->orchestrator(succeed: true)->charge($sub);

        $this->assertTrue($result['success']);
        $this->assertSame('153.90', (string) PaymentLedger::query()->sole()->amount);
        $this->assertDatabaseHas('system_logs', ['subscription_id' => $sub->id, 'level' => 'warning']);
    }

    public function test_a_stale_amount_that_cannot_be_rebuilt_is_never_charged(): void
    {
        $sub = $this->subscriptionWithOneBag(100.00);
        $this->fakeDraftRebuild(null);

        $result = $this->orchestrator(succeed: true)->charge($sub);

        $this->assertFalse($result['success']);
        $this->assertSame('amount_mismatch', $result['status']);
        $this->assertSame(0, PaymentLedger::query()->count());
    }

    public function test_an_amount_that_already_matches_is_not_rebuilt(): void
    {
        $sub = $this->subscriptionWithOneBag(153.90);
        $this->fakeDraftRebuild(999.00);

        $this->assertTrue($this->orchestrator(succeed: true)->charge($sub)['success']);
        $this->assertSame('153.90', (string) PaymentLedger::query()->sole()->amount);
    }

    /** A gateway that approves every charge and counts how many times it was asked. */
    private function countingGateway(): PaymentGateway
    {
        return new class implements PaymentGateway
        {
            public int $charges = 0;

            public function chargeWithReference(string $reference, int $amountAgorot, string $idempotencyKey, array $opts = []): GatewayResult
            {
                $this->charges++;

                return GatewayResult::success('sale_'.$this->charges, ['payme_status_code' => 0]);
            }

            public function lookup(string $idempotencyKey): GatewayResult
            {
                return GatewayResult::success('sale_lookup');
            }
        };
    }

    /** The cycle's ledger row as the retries left it: declined four times, marked failed. */
    private function exhaustedLedgerRow(Subscription $sub, string $status = 'failed'): PaymentLedger
    {
        $key = 'recurring:'.$sub->id.':'.$sub->next_charge_at->toDateString();
        $row = PaymentLedger::query()->create([
            'subscription_id' => $sub->id,
            'customer_id' => $sub->customer_id,
            'context' => 'recurring',
            'idempotency_key' => $key,
            'amount' => 153.90,
            'currency' => 'ILS',
            'failure_code' => '20004',
        ]);
        $row->forceFill(['status' => $status])->save();
        $sub->forceFill(['attempt_count' => 4])->save();

        return $row;
    }

    public function test_a_reactivated_subscription_whose_retries_ran_out_is_charged_once_and_recorded(): void
    {
        // Subscription 766, 2026-11-09: reactivated by the customer after four declines,
        // then charged fourteen times in 65 minutes — every success crashed unrecorded.
        $sub = $this->subscriptionWithOneBag(153.90);
        $row = $this->exhaustedLedgerRow($sub);
        $gateway = $this->countingGateway();
        $orchestrator = new ChargeOrchestrator($gateway);

        $first = $orchestrator->charge($sub->fresh());
        $second = $orchestrator->charge($sub->fresh());

        $this->assertTrue($first['success']);
        $this->assertSame(LedgerStatus::SUCCEEDED, $row->fresh()->status);
        $this->assertSame('sale_1', $row->fresh()->payme_transaction_id);
        $this->assertSame('already_charged', $second['status']);
        $this->assertSame(1, $gateway->charges);
    }

    public function test_a_refunded_row_for_the_same_cycle_is_never_charged_again(): void
    {
        $sub = $this->subscriptionWithOneBag(153.90);
        $row = $this->exhaustedLedgerRow($sub, 'refunded');
        $gateway = $this->countingGateway();

        $result = (new ChargeOrchestrator($gateway))->charge($sub->fresh());

        $this->assertFalse($result['success']);
        $this->assertSame('ledger_blocked', $result['status']);
        $this->assertSame(0, $gateway->charges);
        $this->assertSame(LedgerStatus::REFUNDED, $row->fresh()->status);
    }
}
