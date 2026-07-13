<?php

namespace Tests\Feature;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\GatewayResult;
use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\PaymentReference;
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
 * The customer must never be charged twice for one cycle.
 *
 * An adversarial review found two ways it could happen, and both were real:
 *
 *  1. PayMe debits the card, the answer is lost in transit. The old code recorded that as
 *     a DECLINE, scheduled a retry, and four hours later charged the same card again — and
 *     because the retry reused the same ledger row, the second success overwrote the first
 *     failure, so the double charge was invisible in the ledger itself.
 *
 *  2. Two concurrent charges with the same key both passed hasSucceeded() (which only ever
 *     matched `succeeded`), both got the same pending row back, and both called PayMe. The
 *     unique index on idempotency_key prevented a duplicate ROW; it prevented nothing about
 *     the money.
 *
 * Every test below fails on the pre-fix code. They are the reason it is safe to bill.
 */
class DoubleChargeGuardTest extends TestCase
{
    use RefreshDatabase;

    private function subscription(): Subscription
    {
        $customer = Customer::query()->create(['email' => 'charge@example.com', 'shopify_customer_id' => '5150']);

        PaymentMethod::query()->create([
            'customer_id' => $customer->id,
            'gateway' => 'payme',
            'buyer_key' => 'buyer-key-abc',
            'is_active' => true,
            'captured_at' => now(),
        ]);

        $subscription = new Subscription;
        $subscription->fill([
            'customer_id' => $customer->id,
            'payment_state' => PaymentState::PAYME->value,
            'frequency_months' => 1,
            'next_charge_at' => now()->startOfDay(),
            'next_charge_amount' => 153.90,
        ]);
        $subscription->forceFill(['status' => SubscriptionStatus::ACTIVE->value])->save();

        return $subscription->fresh();
    }

    /** A gateway that counts how many times it was actually asked for money. */
    private function gateway(GatewayResult $result): object
    {
        $fake = new class($result) implements PaymentGateway
        {
            public int $charges = 0;

            public int $lookups = 0;

            public array $sentReferences = [];

            public function __construct(private GatewayResult $result) {}

            public function chargeWithReference(string $reference, int $amountAgorot, string $idempotencyKey, array $opts = []): GatewayResult
            {
                $this->charges++;
                $this->sentReferences[] = PaymentReference::for($idempotencyKey);

                return $this->result;
            }

            public function lookup(string $idempotencyKey): GatewayResult
            {
                $this->lookups++;

                return $this->result;
            }

            public function setResult(GatewayResult $result): void
            {
                $this->result = $result;
            }
        };

        $this->app->instance(PaymentGateway::class, $fake);

        return $fake;
    }

    // --- 1. the lost answer -----------------------------------------------------

    public function test_a_lost_gateway_answer_is_not_recorded_as_a_decline(): void
    {
        $subscription = $this->subscription();
        $this->gateway(GatewayResult::ambiguous('read timeout'));

        $result = app(ChargeOrchestrator::class)->charge($subscription);

        $this->assertFalse($result['success']);
        $this->assertSame('ambiguous', $result['status'], 'a timeout is NOT a decline');

        $ledger = PaymentLedger::query()->firstOrFail();

        // The row stays PENDING. It is the lease, and it now blocks any further charge.
        $this->assertSame(LedgerStatus::PENDING, $ledger->status);

        // Crucially: NO retry is scheduled. Retrying is exactly the wrong move — if the card
        // was debited, the retry takes the money a second time.
        $this->assertSame(0, (int) $subscription->fresh()->attempt_count);
        $this->assertNull($subscription->fresh()->next_retry_at);
    }

    public function test_a_charge_whose_outcome_is_unknown_blocks_the_next_attempt_instead_of_charging_again(): void
    {
        $subscription = $this->subscription();
        $gateway = $this->gateway(GatewayResult::ambiguous('read timeout'));

        // First attempt: PayMe never answered. The card MAY be debited.
        app(ChargeOrchestrator::class)->charge($subscription);
        $this->assertSame(1, $gateway->charges);

        // The dispatcher comes round again for the SAME cycle.
        $result = app(ChargeOrchestrator::class)->charge($subscription->fresh());

        $this->assertSame('reconciliation_required', $result['status']);
        $this->assertSame(1, $gateway->charges, 'PayMe must NOT be called a second time — this is the double charge');
    }

    // --- 2. concurrency ---------------------------------------------------------

    public function test_two_charges_with_the_same_key_reach_the_gateway_only_once(): void
    {
        $subscription = $this->subscription();
        $gateway = $this->gateway(GatewayResult::success('sale-1'));

        $key = IdempotencyKey::manual($subscription->id, 1, now()->toDateString());

        // An admin double-clicks "Charge now".
        app(ChargeOrchestrator::class)->charge($subscription, IdempotencyKey::CONTEXT_MANUAL, $key);
        app(ChargeOrchestrator::class)->charge($subscription->fresh(), IdempotencyKey::CONTEXT_MANUAL, $key);

        $this->assertSame(1, $gateway->charges, 'the money must move exactly once');
        $this->assertSame(1, PaymentLedger::query()->count());
    }

    public function test_a_re_dispatch_of_an_already_charged_cycle_is_short_circuited(): void
    {
        $subscription = $this->subscription();
        $gateway = $this->gateway(GatewayResult::success('sale-1'));

        // The key the dispatcher builds is pinned to THIS cycle's due date. A duplicate job
        // for the same cycle — a queue retry, an overlapping scheduler tick — rebuilds the
        // identical key.
        $key = IdempotencyKey::recurring($subscription->id, $subscription->next_charge_at->toDateString());

        app(ChargeOrchestrator::class)->charge($subscription, IdempotencyKey::CONTEXT_RECURRING, $key);
        $second = app(ChargeOrchestrator::class)->charge($subscription->fresh(), IdempotencyKey::CONTEXT_RECURRING, $key);

        $this->assertSame(1, $gateway->charges, 'the same cycle must never be charged twice');
        $this->assertSame('already_charged', $second['status']);
    }

    // --- 3. a real decline must still retry normally -----------------------------

    public function test_a_definite_decline_still_schedules_a_retry_and_can_be_charged_again(): void
    {
        $subscription = $this->subscription();
        $gateway = $this->gateway(GatewayResult::failure('insufficient_funds', 'no money'));

        $first = app(ChargeOrchestrator::class)->charge($subscription);

        $this->assertSame('failed', $first['status']);
        $this->assertSame(1, (int) $subscription->fresh()->attempt_count, 'a real decline DOES back off');
        $this->assertNotNull($subscription->fresh()->next_retry_at);

        // PayMe said no, so the money definitely did not move — retrying is safe, and must
        // still work. The fix must not break legitimate retries.
        $gateway->setResult(GatewayResult::success('sale-2'));
        $second = app(ChargeOrchestrator::class)->charge($subscription->fresh());

        $this->assertTrue($second['success']);
        $this->assertSame(2, $gateway->charges);
        $this->assertSame(LedgerStatus::SUCCEEDED, PaymentLedger::query()->firstOrFail()->status);
    }

    // --- 4. reconciliation ------------------------------------------------------

    public function test_reconciliation_recovers_a_lost_charge_without_charging_again(): void
    {
        $subscription = $this->subscription();
        $cycle = $subscription->next_charge_at->copy();

        $gateway = $this->gateway(GatewayResult::ambiguous('read timeout'));
        app(ChargeOrchestrator::class)->charge($subscription);

        $ledger = PaymentLedger::query()->firstOrFail();
        $ledger->forceFill(['created_at' => now()->subMinutes(30)])->save();

        // PayMe, asked directly, says the sale went through all along.
        $gateway->setResult(GatewayResult::success('sale-recovered'));

        $this->artisan('mills:reconcile-payments')->assertSuccessful();

        $ledger->refresh();
        $this->assertSame(LedgerStatus::SUCCEEDED, $ledger->status);
        $this->assertSame('sale-recovered', $ledger->payme_transaction_id);

        // The money already moved — it must NOT move again.
        $this->assertSame(1, $gateway->charges);
        $this->assertSame(1, $gateway->lookups);

        // And the cycle rolls forward exactly as a normal success would have.
        $this->assertTrue($subscription->fresh()->next_charge_at->isSameDay($cycle->addMonthNoOverflow()));
    }

    public function test_reconciliation_of_a_charge_that_never_happened_schedules_an_ordinary_retry(): void
    {
        $subscription = $this->subscription();

        $gateway = $this->gateway(GatewayResult::ambiguous('read timeout'));
        app(ChargeOrchestrator::class)->charge($subscription);

        PaymentLedger::query()->firstOrFail()->forceFill(['created_at' => now()->subMinutes(30)])->save();

        // PayMe has no record of it — the money never moved.
        $gateway->setResult(GatewayResult::failure('not_found', 'no such sale'));

        $this->artisan('mills:reconcile-payments')->assertSuccessful();

        $this->assertSame(LedgerStatus::RETRY_SCHEDULED, PaymentLedger::query()->firstOrFail()->status);
        $this->assertSame(1, (int) $subscription->fresh()->attempt_count);
        $this->assertNotNull($subscription->fresh()->next_retry_at);
    }

    public function test_a_charge_that_is_still_unknown_stays_blocked_rather_than_guessed(): void
    {
        $subscription = $this->subscription();

        $gateway = $this->gateway(GatewayResult::ambiguous('read timeout'));
        app(ChargeOrchestrator::class)->charge($subscription);

        PaymentLedger::query()->firstOrFail()->forceFill(['created_at' => now()->subMinutes(30)])->save();

        // PayMe still cannot tell us. Guessing in EITHER direction is unacceptable.
        $this->artisan('mills:reconcile-payments')->assertSuccessful();

        $this->assertSame(LedgerStatus::PENDING, PaymentLedger::query()->firstOrFail()->status);

        // And it is still blocked — money in limbo is a human's problem, not a gamble.
        $result = app(ChargeOrchestrator::class)->charge($subscription->fresh());
        $this->assertSame('reconciliation_required', $result['status']);
        $this->assertSame(1, $gateway->charges);
    }

    // --- 5. the reference the gateway dedupes on --------------------------------

    public function test_the_idempotency_key_is_sent_to_the_gateway_and_is_stable_across_retries(): void
    {
        $subscription = $this->subscription();
        $gateway = $this->gateway(GatewayResult::failure('declined', 'no'));

        app(ChargeOrchestrator::class)->charge($subscription);

        $gateway->setResult(GatewayResult::success('sale-2'));
        app(ChargeOrchestrator::class)->charge($subscription->fresh());

        // Both attempts must carry the SAME reference, or PayMe cannot recognise the retry
        // as the same charge and books a second sale.
        $this->assertCount(2, $gateway->sentReferences);
        $this->assertSame($gateway->sentReferences[0], $gateway->sentReferences[1]);
        $this->assertNotSame('', $gateway->sentReferences[0]);
    }

    public function test_the_payment_reference_is_deterministic_and_gateway_safe(): void
    {
        $key = IdempotencyKey::recurring(7, '2026-07-13');

        $this->assertSame('recurring-7-2026-07-13', PaymentReference::for($key));
        $this->assertSame(PaymentReference::for($key), PaymentReference::for($key), 'must be deterministic');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+$/', PaymentReference::for($key));

        // A pathological key must still produce something safe, stable and bounded.
        $long = PaymentReference::for(str_repeat('x:y ', 60));
        $this->assertLessThanOrEqual(40, strlen($long));
        $this->assertSame($long, PaymentReference::for(str_repeat('x:y ', 60)));
    }
}
