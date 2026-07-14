<?php

namespace App\Console\Commands;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Models\Customer;
use App\Models\PaymentLedger;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Services\CardUpdateService;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Support\Timeline;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recover cards that PayMe captured and we never received.
 *
 * The buyer_key is only fetched when the customer's BROWSER returns to our callback. If they
 * close the tab on PayMe's page — or open a card-update link we sent by SMS and wander off —
 * PayMe has taken the verification charge and tokenised the card, and we hold nothing. The
 * customer is left blocked from being billed, their money has moved, and nobody finds out:
 * the failure is completely silent.
 *
 * The pending `card_update` ledger row is the trace that survives the 15-minute session cache.
 * This command asks PayMe, once, what became of each abandoned one:
 *
 *   a buyer_key exists → the card WAS captured. Store it, lift the wall, settle the row.
 *   none, ever         → the shopper walked away before entering a card. Close it as failed.
 *   PayMe won't say    → leave it pending and SAY SO. Never guess about a card.
 */
class ReconcileCardUpdatesCommand extends Command
{
    protected $signature = 'mills:reconcile-card-updates
        {--minutes=20 : Only sweep sessions older than this (past the 15-minute TTL, so a live browser is never raced)}
        {--limit=100 : Maximum rows to reconcile in one pass}';

    protected $description = 'Recover card updates where PayMe captured the card but the customer never returned to us.';

    public function handle(PaymeClient $payme, CardUpdateService $cardUpdate): int
    {
        $minutes = max(1, (int) $this->option('minutes'));

        $abandoned = PaymentLedger::query()
            ->where('context', IdempotencyKey::CONTEXT_CARD_UPDATE)
            ->where('status', LedgerStatus::PENDING->value)
            ->where('created_at', '<=', now()->subMinutes($minutes))
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->get();

        if ($abandoned->isEmpty()) {
            $this->info('No abandoned card updates.');

            return self::SUCCESS;
        }

        $this->warn("{$abandoned->count()} card update(s) left unfinished.");

        $recovered = 0;
        $closed = 0;
        $stuck = 0;

        foreach ($abandoned as $ledger) {
            try {
                $result = $payme->getBuyerKey((string) $ledger->payme_transaction_id);
            } catch (Throwable $e) {
                // PayMe is unreachable. Leave the row alone and try again next pass — the
                // one thing we must never do is decide anything about a card on a guess.
                $stuck++;
                $this->error("#{$ledger->id}: PayMe unreachable — {$e->getMessage()}");

                continue;
            }

            $buyerKey = (string) ($result['buyer_key'] ?? '');

            if ($buyerKey === '') {
                $this->closeAbandoned($ledger);
                $closed++;

                continue;
            }

            $customer = Customer::query()->find($ledger->customer_id);

            if ($customer === null) {
                $stuck++;
                $this->error("#{$ledger->id}: a card was captured for a customer that no longer exists.");

                continue;
            }

            $cardUpdate->storeBuyerKey($customer, $buyerKey, (string) ($result['masked_card'] ?? $result['card_mask'] ?? ''));
            $lifted = $cardUpdate->liftCardUpdateWall($customer);

            Ledger::transition($ledger, LedgerStatus::SUCCEEDED, ['executed_at' => now()]);

            SystemLog::warning('billing', 'a card was captured at PayMe but never returned to us — recovered by reconciliation', [
                'ledger_id' => $ledger->id,
                'subscriptions_unblocked' => $lifted,
            ], ['subscription_id' => $ledger->subscription_id, 'customer_id' => $customer->id]);

            Timeline::record(
                Timeline::KIND_CARD_UPDATED,
                ['subscriptions_unblocked' => $lifted, 'recovered_by_reconciliation' => true],
                $ledger->subscription_id,
                $customer->id,
                // The system found this, not the person who started it.
                Timeline::ACTOR_SYSTEM,
            );

            $recovered++;
            $this->info("#{$ledger->id}: card recovered, {$lifted} subscription(s) unblocked.");
        }

        $this->line("recovered={$recovered} closed={$closed} stuck={$stuck}");

        return self::SUCCESS;
    }

    /**
     * No buyer_key, well past the session TTL: the shopper never entered a card. Closing the
     * row keeps the reconciler from asking PayMe about it forever.
     */
    private function closeAbandoned(PaymentLedger $ledger): void
    {
        Ledger::transition($ledger, LedgerStatus::FAILED, [
            'failure_code' => 'abandoned',
            'failure_message' => 'the card-update page was opened but no card was entered',
        ]);

        SystemLog::info('billing', 'card-update session abandoned — no card was entered', [
            'ledger_id' => $ledger->id,
        ], ['subscription_id' => $ledger->subscription_id, 'customer_id' => $ledger->customer_id]);

        $this->line("#{$ledger->id}: abandoned.");
    }
}
