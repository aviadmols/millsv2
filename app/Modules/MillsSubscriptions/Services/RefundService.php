<?php

namespace App\Modules\MillsSubscriptions\Services;

use App\Domain\Billing\IdempotencyKey;
use App\Domain\Billing\Ledger;
use App\Models\PaymentLedger;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Enums\LedgerStatus;
use App\Modules\MillsSubscriptions\Services\PayMe\PaymeClient;
use App\Modules\MillsSubscriptions\Services\Shopify\OrderCreationService;
use App\Modules\MillsSubscriptions\Services\Shopify\ShopifyAdminClient;
use App\Modules\MillsSubscriptions\Support\Timeline;
use App\Support\PaymentContextMasker;
use RuntimeException;
use Throwable;

/**
 * Give a customer their money back — for real.
 *
 * Refunding in Shopify does nothing to the card. The recurring charge never went through
 * Shopify; it went through PayMe, and the order was only RECORDED in Shopify afterwards as
 * an external transaction. A Shopify refund on that order is bookkeeping: it marks the
 * order refunded and the money stays exactly where it was. An admin did that on order
 * 19019226579248, in good faith, and the customer got nothing.
 *
 * This is the one place a refund is real. In order:
 *
 *   1. PayMe moves the money back, against the sale id we stored when we took it.
 *   2. The ledger records it — the only book this business has of what it took.
 *   3. Shopify's order is marked refunded to match, so the three agree.
 *
 * Step 1 is the money. If it fails, nothing else happens and the admin is told. Steps 2
 * and 3 are compensating: once PayMe has confirmed the refund the money HAS moved, and a
 * failure to write it down is a repairable problem, whereas pretending it did not happen
 * would be a second refund waiting to be issued.
 */
class RefundService
{
    public function __construct(
        private readonly PaymeClient $payme,
        private readonly ShopifyAdminClient $shopify,
    ) {}

    /**
     * @param  float|null  $amount  ILS; null refunds the whole charge
     * @param  string  $actor  Timeline actor — who pressed the button
     * @return array{refunded: float, full: bool}
     *
     * @throws RuntimeException with a translatable code when the refund cannot be issued
     */
    public function refund(PaymentLedger $ledger, ?float $amount, string $actor, string $reason = ''): array
    {
        $status = $ledger->status instanceof LedgerStatus ? $ledger->status : LedgerStatus::from((string) $ledger->status);

        // Only money we actually took can go back. A pending or failed charge took nothing,
        // and a refunded one already went back — refunding it again would pay the customer
        // twice out of the store's pocket.
        if ($status !== LedgerStatus::SUCCEEDED) {
            throw new RuntimeException('refund_not_succeeded');
        }

        if (! in_array($ledger->context, IdempotencyKey::billingContexts(), true)) {
            throw new RuntimeException('refund_not_a_charge');
        }

        $saleId = trim((string) $ledger->payme_transaction_id);
        if ($saleId === '') {
            throw new RuntimeException('refund_no_sale_id');
        }

        $charged = round((float) $ledger->amount, 2);
        $alreadyRefunded = round((float) ($ledger->refunded_amount ?? 0), 2);
        $remaining = round($charged - $alreadyRefunded, 2);

        $amount = $amount === null ? $remaining : round($amount, 2);

        if ($amount <= 0 || $amount > $remaining + 0.005) {
            throw new RuntimeException('refund_amount_out_of_range');
        }

        // --- 1. the money ---------------------------------------------------------------

        $response = $this->payme->refundSale($saleId, (int) round($amount * 100));

        if ((int) ($response['status_code'] ?? -1) !== 0) {
            SystemLog::error('billing', 'PayMe refused the refund', [
                'ledger_id' => $ledger->id,
                'amount' => $amount,
                'payme' => PaymentContextMasker::mask($response),
            ], ['subscription_id' => $ledger->subscription_id, 'customer_id' => $ledger->customer_id]);

            throw new RuntimeException('refund_payme_refused');
        }

        // --- 2. the books ---------------------------------------------------------------

        $totalRefunded = round($alreadyRefunded + $amount, 2);
        $full = $totalRefunded >= $charged - 0.005;

        $patch = [
            'refunded_amount' => $totalRefunded,
            'refunded_at' => now(),
        ];

        // The status is a state machine with one refund state and no "partly". A partial
        // refund therefore stays SUCCEEDED — the charge did succeed — with the amount that
        // went back written beside it, and the row turns REFUNDED only once all of it has.
        $full
            ? Ledger::transition($ledger, LedgerStatus::REFUNDED, $patch)
            : $ledger->forceFill($patch)->save();

        Timeline::record(Timeline::KIND_CHARGE_REFUNDED, [
            'ledger_id' => $ledger->id,
            'amount' => number_format($amount, 2, '.', ''),
            'full' => $full,
            'reason' => $reason,
        ], $ledger->subscription_id, $ledger->customer_id, $actor);

        SystemLog::info('billing', $full ? 'charge refunded in full' : 'charge partly refunded', [
            'ledger_id' => $ledger->id,
            'amount' => $amount,
            'total_refunded' => $totalRefunded,
            'actor' => $actor,
        ], ['subscription_id' => $ledger->subscription_id, 'customer_id' => $ledger->customer_id]);

        // --- 3. Shopify, so the order stops claiming the money is still in the till ------

        $this->recordInShopify($ledger, $amount, $reason);

        return ['refunded' => $amount, 'full' => $full];
    }

    /**
     * Mark the order refunded in Shopify to match what PayMe just did.
     *
     * Compensating, never blocking: the money has already moved. Shopify needs the id of
     * the sale transaction on the order to hang the refund on, so it is looked up first;
     * an order we never managed to create has nothing to update and is simply skipped.
     */
    private function recordInShopify(PaymentLedger $ledger, float $amount, string $reason): void
    {
        $orderId = trim((string) $ledger->shopify_order_id);

        if ($orderId === '' || ! $this->shopify->isConnected()) {
            return;
        }

        try {
            $transactions = $this->shopify->restGet("orders/{$orderId}/transactions.json")['transactions'] ?? [];

            $parent = null;
            foreach ($transactions as $tx) {
                if (($tx['kind'] ?? '') === 'sale' && ($tx['status'] ?? '') === 'success') {
                    $parent = $tx;
                    break;
                }
            }

            $refundTx = [
                'amount' => number_format($amount, 2, '.', ''),
                'kind' => 'refund',
                'gateway' => (string) ($parent['gateway'] ?? OrderCreationService::gatewayLabel()),
            ];

            if ($parent !== null) {
                $refundTx['parent_id'] = (int) $parent['id'];
            }

            $this->shopify->restPost("orders/{$orderId}/refunds.json", [
                'refund' => [
                    'currency' => $ledger->currency ?: 'ILS',
                    'notify' => false,
                    'note' => trim($reason) !== '' ? $reason : 'הוחזר דרך PayMe',
                    'transactions' => [$refundTx],
                ],
            ]);
        } catch (Throwable $e) {
            SystemLog::warning('billing', 'refund done in PayMe but could not be recorded on the Shopify order', [
                'ledger_id' => $ledger->id,
                'shopify_order_id' => $orderId,
                'message' => $e->getMessage(),
            ], ['subscription_id' => $ledger->subscription_id, 'customer_id' => $ledger->customer_id]);
        }
    }
}
