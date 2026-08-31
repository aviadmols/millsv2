<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\PaymentLedger;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Support\DiscountResolver;
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

            // The order must total what was actually taken, or Shopify records it as
            // partially paid and it sits in the admin looking like an unpaid debt.
            $order = $this->reconcileToAmountPaid($order, $lineItems, $subscription, $ledger);

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

    /**
     * Make the order total equal the money that was actually taken.
     *
     * Shopify derives an order's financial status from its TRANSACTIONS, so an order whose
     * lines add up to ₪171.00 carrying a ₪153.90 sale is not "paid" — it is PARTIALLY PAID,
     * with ₪17.10 outstanding, and it sits in the merchant's admin looking like a customer
     * who still owes money. Every discounted recurring order was created that way: the
     * discount was applied when calculating the charge and then never carried onto the
     * order it paid for.
     *
     * The fix is to state the discount on the order itself, as a named line the customer
     * can read on their invoice. `fixed_amount` rather than a percentage: the exact shekel
     * gap is the only value guaranteed to reconcile to the last agora, and a rounding
     * difference of one agora is enough to leave the order partially paid all over again.
     *
     * Priced from OUR cache so the arithmetic is ours end to end — Shopify pricing the
     * lines itself would reintroduce the very mismatch this exists to close. A line we
     * cannot price means we cannot do the sum honestly, so the order is created exactly as
     * before and the discrepancy is logged rather than papered over with a guessed number.
     *
     * @param  array<int, array<string, mixed>>  $lineItems
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function reconcileToAmountPaid(array $order, array $lineItems, Subscription $subscription, PaymentLedger $ledger): array
    {
        $paid = round((float) $ledger->amount, 2);

        if ($paid <= 0) {
            return $order;
        }

        $prices = ProductVariant::query()
            ->whereIn('shopify_variant_id', array_map(fn (array $l) => (string) $l['variant_id'], $lineItems))
            ->pluck('price', 'shopify_variant_id');

        $subtotal = 0.0;

        foreach ($lineItems as $i => $line) {
            $price = $prices[(string) $line['variant_id']] ?? null;

            if ($price === null) {
                SystemLog::warning('billing', 'order created without a reconciled total — a variant is not in the product cache', [
                    'variant_id' => $line['variant_id'],
                    'ledger_id' => $ledger->id,
                ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

                return $order;
            }

            // Always two decimals: money sent as "171" and money sent as "171.00" are the
            // same number to PHP and not always to an API that parses currency strings.
            $order['line_items'][$i]['price'] = number_format((float) $price, 2, '.', '');
            $subtotal += round((float) $price, 2) * (int) $line['quantity'];
        }

        $subtotal = round($subtotal, 2);
        $discount = round($subtotal - $paid, 2);

        if ($discount <= 0) {
            // Charged the full price, or more than the lines come to. Nothing to explain —
            // and inventing a NEGATIVE discount to force a match would be a lie on an invoice.
            if ($discount < 0) {
                SystemLog::warning('billing', 'the charge exceeds the order total — order left for a human to look at', [
                    'charged' => $paid,
                    'line_items_total' => $subtotal,
                    'ledger_id' => $ledger->id,
                ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);
            }

            return $order;
        }

        $order['discount_codes'] = [[
            'code' => $this->discountTitle($subscription),
            'amount' => number_format($discount, 2, '.', ''),
            'type' => 'fixed_amount',
        ]];

        return $order;
    }

    /**
     * What the discount is called on the customer's invoice.
     *
     * The rule's own name when a rule granted it — that is the wording the merchant chose
     * for customers to read — and the generic subscriber wording otherwise.
     */
    private function discountTitle(Subscription $subscription): string
    {
        $decision = DiscountResolver::for($subscription, app(DraftOrderService::class)->lineItems($subscription));

        $name = trim((string) ($decision['rule_name'] ?? ''));

        return $name !== '' ? $name : __('subscriptions.discount_title');
    }
}
