<?php

namespace Tests\Feature;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayResult;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\PaymentMethod;
use App\Models\Subscription;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\ChargeOrchestrator;
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
}
