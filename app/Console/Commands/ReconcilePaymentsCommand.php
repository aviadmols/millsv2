<?php

namespace App\Console\Commands;

use App\Domain\Billing\Contracts\PaymentGateway;
use App\Domain\Billing\Ledger;
use App\Models\PaymentLedger;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Services\Shopify\DraftOrderService;
use App\Modules\MillsSubscriptions\Services\Shopify\OrderCreationService;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Console\Command;
use Throwable;

/**
 * Resolve charges whose outcome we never learned.
 *
 * A `pending` ledger row means PayMe was called and never answered — a timeout, a dropped
 * connection, a worker killed mid-request. The card may or may not have been debited, and
 * that row now BLOCKS any further charge for its key (ChargeOrchestrator refuses while it
 * is pending). That block is deliberate: retrying a charge that may already have gone
 * through is how a customer is billed twice, and a double charge is the one failure this
 * system cannot undo.
 *
 * This command is the way out. It asks PayMe what actually happened — the idempotency key
 * was sent as PayMe's own transaction reference, so the sale can be looked up rather than
 * guessed at — and then finishes the job the original attempt started:
 *
 *   paid     → mark succeeded, advance the cycle, create the Shopify order. No re-charge.
 *   declined → mark failed and schedule the ordinary backoff. Safe to retry.
 *   unknown  → leave it pending and SAY SO. Money in limbo is a human's problem, not a
 *              reason to gamble.
 */
class ReconcilePaymentsCommand extends Command
{
    protected $signature = 'mills:reconcile-payments
        {--minutes=10 : Only reconcile rows left pending for at least this long}
        {--limit=100 : Maximum rows to reconcile in one pass}';

    protected $description = 'Ask PayMe what happened to charges whose answer was lost, and resolve them without ever re-charging.';

    public function handle(PaymentGateway $gateway): int
    {
        $minutes = max(1, (int) $this->option('minutes'));

        // A charge that is only seconds old is probably still in flight — give it time to
        // answer before we go asking about it.
        $stale = PaymentLedger::query()
            ->where('status', LedgerStatus::PENDING->value)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->warn("{$stale->count()} charge(s) with an unknown outcome.");

        $paid = 0;
        $declined = 0;
        $stillUnknown = 0;

        foreach ($stale as $ledger) {
            $result = $gateway->lookup((string) $ledger->idempotency_key);

            if ($result->ambiguous) {
                $stillUnknown++;
                $this->error("  #{$ledger->id} ({$ledger->idempotency_key}): STILL UNKNOWN — {$result->failureMessage}");

                SystemLog::error('billing', 'charge outcome still unknown after reconciliation', [
                    'ledger_id' => $ledger->id,
                    'idempotency_key' => $ledger->idempotency_key,
                    'amount' => (string) $ledger->amount,
                    'pending_since' => $ledger->created_at?->toIso8601String(),
                    'reason' => $result->failureMessage,
                ], ['subscription_id' => $ledger->subscription_id, 'customer_id' => $ledger->customer_id]);

                continue;
            }

            if ($result->success) {
                $this->info("  #{$ledger->id}: PAID — the money did move. Completing without re-charging.");
                $this->settle($ledger, $result->transactionId, $result->raw);
                $paid++;

                continue;
            }

            $this->line("  #{$ledger->id}: declined — the money did not move. Safe to retry.");
            $this->markFailed($ledger, $result->failureCode, $result->failureMessage, $result->raw);
            $declined++;
        }

        $this->newLine();
        $this->info("recovered={$paid}  declined={$declined}  still_unknown={$stillUnknown}");

        SystemLog::info('billing', 'payment reconciliation pass', [
            'recovered' => $paid,
            'declined' => $declined,
            'still_unknown' => $stillUnknown,
        ]);

        return self::SUCCESS;
    }

    /**
     * The charge DID go through. Finish everything the original attempt would have done —
     * but never call PayMe again.
     *
     * @param  array<string, mixed>  $raw
     */
    private function settle(PaymentLedger $ledger, ?string $transactionId, array $raw): void
    {
        Ledger::transition($ledger, LedgerStatus::SUCCEEDED, [
            'payme_transaction_id' => $transactionId,
            'raw_response_masked' => $raw,
            'executed_at' => now(),
            'failure_code' => null,
            'failure_message' => null,
        ]);

        $subscription = $ledger->subscription;

        if ($subscription === null) {
            return;
        }

        // Advance the cycle exactly as onSuccess() would have.
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
            'transaction_id' => $transactionId,
            'recovered_by_reconciliation' => true,
        ], $subscription->id, $subscription->customer_id);

        SystemLog::warning('billing', 'a lost charge was recovered — the customer WAS charged', [
            'ledger_id' => $ledger->id,
            'amount' => (string) $ledger->amount,
        ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

        // The money moved, so the order still owes the customer its existence.
        try {
            app(OrderCreationService::class)->createPaidOrder($subscription, $ledger->fresh());
            app(DraftOrderService::class)->refresh($subscription->fresh());
        } catch (Throwable $e) {
            SystemLog::error('billing', 'recovered the charge but could not create the order', [
                'ledger_id' => $ledger->id,
                'message' => $e->getMessage(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
        }
    }

    /**
     * PayMe never took the money. This is now an ordinary decline: schedule the backoff and
     * let the dispatcher try again.
     *
     * @param  array<string, mixed>  $raw
     */
    private function markFailed(PaymentLedger $ledger, ?string $code, ?string $message, array $raw): void
    {
        Ledger::transition($ledger, LedgerStatus::FAILED, [
            'failure_code' => $code,
            'failure_message' => $message,
            'raw_response_masked' => $raw,
            'executed_at' => now(),
        ]);

        $subscription = $ledger->subscription;

        if ($subscription === null) {
            return;
        }

        $backoff = (array) config('billing.retry_backoff_hours', [4, 24, 72]);
        $attempt = ((int) $subscription->attempt_count) + 1;

        if ($attempt <= count($backoff)) {
            $subscription->forceFill([
                'attempt_count' => $attempt,
                'next_retry_at' => now()->addHours((int) $backoff[$attempt - 1]),
            ])->save();

            Ledger::transition($ledger->fresh(), LedgerStatus::RETRY_SCHEDULED);
        }

        Timeline::record(Timeline::KIND_CHARGE_FAILED, [
            'ledger_id' => $ledger->id,
            'attempt' => $attempt,
            'code' => $code,
            'resolved_by_reconciliation' => true,
        ], $subscription->id, $subscription->customer_id);
    }
}
