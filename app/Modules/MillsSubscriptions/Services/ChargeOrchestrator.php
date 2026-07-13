<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Models\PaymentLedger;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Enums\PaymentState;
use App\Modules\MillsSubscriptions\Enums\SubscriptionStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Services\Shopify\OrderCreationService;
use App\Modules\MillsSubscriptions\Support\SubscriptionPricing;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The charge pipeline (CLAUDE.md laws #2/#4/#5, ARCHITECTURE.md §5). Money truth
 * first, side effects after: lock → succeeded-precheck → payment-method precheck
 * (fail closed) → open pending ledger → PayMe charge → transition → advance the
 * cycle + Timeline, then the compensating Shopify order (channel-attributed).
 * Failures schedule the [4,24,72]h backoff; exhausted → past_due.
 */
class ChargeOrchestrator
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    /**
     * @return array{success: bool, status: string, ledger_id?: int, error?: string}
     */
    public function charge(Subscription $subscription, string $context = IdempotencyKey::CONTEXT_RECURRING, ?string $idempotencyKey = null): array
    {
        if (config('billing.kill_switch')) {
            Log::warning('billing.kill_switch_active', ['subscription_id' => $subscription->id]);

            return ['success' => false, 'status' => 'kill_switch'];
        }

        // Lock + preconditions + open the pending ledger row in one transaction.
        [$ledger, $preflight] = DB::transaction(function () use ($subscription, $context, &$idempotencyKey) {
            /** @var Subscription $locked */
            $locked = Subscription::query()->lockForUpdate()->findOrFail($subscription->id);

            if ($locked->currentStatus() !== SubscriptionStatus::ACTIVE) {
                return [null, ['success' => false, 'status' => 'not_active']];
            }

            if ($locked->payment_state !== PaymentState::PAYME) {
                return [null, ['success' => false, 'status' => 'needs_card_update']];
            }

            $method = $locked->customer?->activePaymentMethod();
            if ($method === null) {
                // Fail closed: no saved card → require a card update, don't charge.
                $locked->forceFill(['payment_state' => PaymentState::NEEDS_CARD_UPDATE->value])->save();
                Timeline::record(Timeline::KIND_STATUS_CHANGED, ['payment_state' => 'needs_card_update', 'reason' => 'no_payment_method'], $locked->id, $locked->customer_id);

                return [null, ['success' => false, 'status' => 'needs_card_update']];
            }

            $amount = $this->resolveAmount($locked);
            if ($amount <= 0) {
                return [null, ['success' => false, 'status' => 'no_amount']];
            }

            $cycleDate = ($locked->next_charge_at ?? now())->toDateString();
            $idempotencyKey ??= IdempotencyKey::recurring($locked->id, $cycleDate);

            if (Ledger::hasSucceeded($idempotencyKey)) {
                return [null, ['success' => true, 'status' => 'already_charged']];
            }

            $row = Ledger::open($context, $idempotencyKey, $amount, config('billing.currency', 'ILS'), [
                'subscription_id' => $locked->id,
                'customer_id' => $locked->customer_id,
                'payment_method_id' => $method->id,
            ]);

            return [$row, ['amount' => $amount, 'method_id' => $method->id]];
        });

        if ($ledger === null) {
            return $preflight + ['success' => $preflight['success'] ?? false];
        }

        // Charge OUTSIDE the transaction (no DB lock held across the HTTP call).
        $method = $subscription->customer->activePaymentMethod();
        $amountAgorot = (int) round(((float) $preflight['amount']) * 100);

        $result = $this->gateway->chargeWithReference(
            (string) $method->buyer_key,
            $amountAgorot,
            (string) $ledger->idempotency_key,
        );

        return $result->success
            ? $this->onSuccess($subscription, $ledger, $result->transactionId, $result->raw)
            : $this->onFailure($subscription, $ledger, $result->failureCode, $result->failureMessage, $result->raw);
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array{success: bool, status: string, ledger_id: int}
     */
    private function onSuccess(Subscription $subscription, PaymentLedger $ledger, ?string $txId, array $raw): array
    {
        Ledger::transition($ledger, LedgerStatus::SUCCEEDED, [
            'payme_transaction_id' => $txId,
            'raw_response_masked' => $raw,
            'executed_at' => now(),
        ]);

        $months = max(1, (int) $subscription->frequency_months);
        $base = $subscription->next_charge_at ?? now();

        $subscription->forceFill([
            'next_charge_at' => $base->copy()->addMonthsNoOverflow($months),
            'attempt_count' => 0,
            'next_retry_at' => null,
        ])->save();

        Timeline::record(Timeline::KIND_CHARGE_SUCCEEDED, [
            'ledger_id' => $ledger->id,
            'amount' => (string) $ledger->amount,
            'transaction_id' => $txId,
        ], $subscription->id, $subscription->customer_id);

        // Compensating side effect: create the channel-attributed Shopify order.
        // Skipped (logged) until the Shopify app is connected — never unwinds money.
        $this->createShopifyOrder($subscription, $ledger);

        return ['success' => true, 'status' => 'charged', 'ledger_id' => $ledger->id];
    }

    /**
     * @param  array<string,mixed>  $raw
     * @return array{success: bool, status: string, ledger_id: int, error: string}
     */
    private function onFailure(Subscription $subscription, PaymentLedger $ledger, ?string $code, ?string $message, array $raw): array
    {
        Ledger::transition($ledger, LedgerStatus::FAILED, [
            'failure_code' => $code,
            'failure_message' => $message,
            'raw_response_masked' => $raw,
            'executed_at' => now(),
        ]);

        $backoff = (array) config('billing.retry_backoff_hours', [4, 24, 72]);
        $attempt = ((int) $subscription->attempt_count) + 1;

        if ($attempt <= count($backoff)) {
            $subscription->forceFill([
                'attempt_count' => $attempt,
                'next_retry_at' => now()->addHours((int) $backoff[$attempt - 1]),
            ])->save();
            Ledger::transition($ledger, LedgerStatus::RETRY_SCHEDULED);
        } else {
            $subscription->transitionTo(SubscriptionStatus::PAST_DUE, ['reason' => 'retries_exhausted']);
        }

        Timeline::record(Timeline::KIND_CHARGE_FAILED, [
            'ledger_id' => $ledger->id,
            'attempt' => $attempt,
            'code' => $code,
            'message' => $message,
        ], $subscription->id, $subscription->customer_id);

        return ['success' => false, 'status' => 'failed', 'ledger_id' => $ledger->id, 'error' => (string) $message];
    }

    /**
     * The recurring charge amount (ILS) — the total of the upcoming order.
     *
     * Returns 0 when the amount is genuinely unknown, which aborts the charge upstream
     * (`no_amount`). That is deliberate: a subscription whose price we cannot establish
     * must not be billed a guess. The number comes from the draft order, so what the
     * admin sees on screen and what PayMe is asked for are the same number.
     */
    private function resolveAmount(Subscription $subscription): float
    {
        return SubscriptionPricing::amount($subscription) ?? 0.0;
    }

    /**
     * Compensating side effect: record the paid order in Shopify, then build the preview
     * draft for the NEXT cycle (which also refreshes next_charge_amount).
     *
     * Neither may unwind the charge. The money has moved; a failure here is logged loudly
     * and left for repair, because a missing order is fixable and a double charge is not.
     */
    private function createShopifyOrder(Subscription $subscription, PaymentLedger $ledger): void
    {
        app(OrderCreationService::class)->createPaidOrder($subscription, $ledger);

        try {
            app(DraftOrderService::class)->refresh($subscription->fresh());
        } catch (Throwable $e) {
            SystemLog::warning('billing', 'could not build the next upcoming order', [
                'message' => $e->getMessage(),
                'ledger_id' => $ledger->id,
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
        }
    }
}
