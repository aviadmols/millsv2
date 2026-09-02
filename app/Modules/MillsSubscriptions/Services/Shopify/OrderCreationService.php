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

            // Where the box goes. Attaching the customer does NOT put an address on the
            // order — Shopify copies nothing from the customer record for an order created
            // through the API — so without this every recurring order arrived unshippable.
            $order = $this->addShippingAddress($order, $subscription, $ledger);

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
     * Put the customer's delivery address on the order.
     *
     * `customer: {id}` links the customer and nothing more: Shopify does not copy their
     * default address onto an order created through the API, the way its own checkout does.
     * That single missing step is why every order this system ever created arrived with no
     * address — the money taken, the order recorded, and nobody able to say where the box
     * goes (orders 74411, 74412, 74457).
     *
     * The address we hold locally IS the authority — it is what CustomerAddressPusher sends
     * to Shopify as the customer's default — so the same mapping is used here, and the
     * billing address is set to match: nothing in this system ever collects a separate one,
     * and leaving it empty makes an order look half-filled in every export that reads it.
     *
     * An address too thin to ship to does NOT block the order. The card is already charged,
     * and a missing order is worse than an unshippable one — but it is logged as the
     * operational problem it is, rather than being created quietly.
     *
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function addShippingAddress(array $order, Subscription $subscription, PaymentLedger $ledger): array
    {
        $customer = $subscription->customer;

        $address = array_filter([
            'first_name' => $customer?->first_name,
            'last_name' => $customer?->last_name,
            'address1' => $customer?->address1,
            'address2' => $customer?->address2,
            'city' => $customer?->city,
            'province' => $customer?->province,
            'country' => $customer?->country,
            'zip' => $customer?->zip,
            'phone' => $customer?->phone,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        // A street and a city are the minimum a courier can work with. Anything less is not
        // a partial address, it is no address — and sending it would dress the same failure
        // up as success.
        if (empty($address['address1']) || empty($address['city'])) {
            SystemLog::warning('billing', 'order created with no deliverable address — nobody can ship it', [
                'ledger_id' => $ledger->id,
                'has_address1' => ! empty($address['address1']),
                'has_city' => ! empty($address['city']),
            ], ['subscription_id' => $subscription->id, 'customer_id' => $subscription->customer_id]);

            return $order;
        }

        $order['shipping_address'] = $address;
        $order['billing_address'] = $address;

        return $order;
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
