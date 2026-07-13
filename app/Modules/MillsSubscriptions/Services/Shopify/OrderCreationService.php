<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\PaymentLedger;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Support\ShopifyId;
use Throwable;

/**
 * Creates the Shopify order for a charge that has ALREADY been paid.
 *
 * Money moved through PayMe before we get here, so the order is recorded as paid with an
 * inline transaction (kind: sale, status: success, gateway: manual, source: external) —
 * Shopify is not asked to collect anything, only to record what happened. This is why it
 * is not a draft-completion: the draft is a preview, the charge is the truth.
 *
 * Every order goes through ShopifyOrderAttribution so it lands under the app's Sales
 * Channel (CLAUDE.md law #11) — a plain source_name does not populate Shopify's native
 * Channel column; the channel handle does.
 *
 * COMPENSATING, NEVER BLOCKING: the customer's card is already charged. If Shopify
 * refuses the order we log loudly and leave the ledger intact — we must never unwind or
 * re-attempt money because a downstream write failed. The missing order is a repairable
 * problem; a double charge is not.
 */
class OrderCreationService
{
    public function __construct(private readonly ShopifyAdminClient $client) {}

    /**
     * @return string|null the numeric Shopify order id, or null when it could not be created
     */
    public function createPaidOrder(Subscription $subscription, PaymentLedger $ledger): ?string
    {
        if (! $this->client->isConnected()) {
            SystemLog::error('billing', 'charge succeeded but Shopify is not connected — no order created', [
                'ledger_id' => $ledger->id,
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

            return null;
        }

        $lineItems = $this->lineItems($subscription);

        if ($lineItems === []) {
            SystemLog::error('billing', 'charge succeeded but the subscription has no products — no order created', [
                'ledger_id' => $ledger->id,
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

            return null;
        }

        try {
            $order = [
                'line_items' => $lineItems,
                'financial_status' => 'paid',
                'currency' => $ledger->currency ?: 'ILS',
                'send_receipt' => false,
                'send_fulfillment_receipt' => false,
                'inventory_behaviour' => 'decrement_obeying_policy',
                'transactions' => [[
                    'kind' => 'sale',
                    'status' => 'success',
                    'amount' => (string) $ledger->amount,
                    'gateway' => (string) config('shopify.order_tx_gateway', 'manual'),
                    'source' => (string) config('shopify.order_tx_source', 'external'),
                ]],
            ];

            if (! empty($subscription->customer?->shopify_customer_id)) {
                $order['customer'] = ['id' => (int) $subscription->customer->shopify_customer_id];
            }

            // The ONLY path that stamps channel attribution — never bypass it.
            $order = ShopifyOrderAttribution::apply($order, $subscription->id);

            $response = $this->client->restPost('orders.json', ['order' => $order]);

            $orderId = ShopifyId::numeric((string) ($response['order']['id'] ?? ''));

            if ($orderId === '') {
                SystemLog::error('billing', 'Shopify refused the paid order', [
                    'ledger_id' => $ledger->id,
                    'response' => $response['errors'] ?? $response,
                ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

                return null;
            }

            $ledger->forceFill(['shopify_order_id' => $orderId])->save();

            SystemLog::info('billing', 'Shopify order created for the charge', [
                'order_id' => $orderId,
                'order_name' => $response['order']['name'] ?? null,
                'amount' => (string) $ledger->amount,
                'ledger_id' => $ledger->id,
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

            return $orderId;
        } catch (Throwable $e) {
            // The card is already charged. Log and move on — never let this unwind money.
            SystemLog::error('billing', 'order creation threw after a successful charge', [
                'ledger_id' => $ledger->id,
                'message' => $e->getMessage(),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

            return null;
        }
    }

    /**
     * REST wants numeric variant ids, not GIDs.
     *
     * @return list<array{variant_id: int, quantity: int}>
     */
    private function lineItems(Subscription $subscription): array
    {
        $items = [];

        foreach (app(DraftOrderService::class)->lineItems($subscription) as $item) {
            $variantId = ShopifyId::numeric((string) $item['variantId']);
            if ($variantId === '') {
                continue;
            }

            $items[] = [
                'variant_id' => (int) $variantId,
                'quantity' => (int) $item['quantity'],
            ];
        }

        return $items;
    }
}
