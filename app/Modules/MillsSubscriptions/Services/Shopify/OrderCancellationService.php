<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\SystemLog;
use RuntimeException;
use Throwable;

/**
 * Cancel an order in Shopify — the shipment, not the money.
 *
 * Deliberately separate from RefundService. Cancelling stops a box being sent; refunding
 * returns what was paid. They are usually done together and are still two decisions: a
 * customer who cancels after receiving the box gets no refund, and one refunded for a
 * damaged delivery still had their order shipped.
 *
 * Shopify's own cancel can carry a refund, and we never use it: the money went through
 * PayMe, so a refund asked for here would be recorded and never paid. RefundService moves
 * the money first; this only closes the order.
 */
class OrderCancellationService
{
    public function __construct(private readonly ShopifyAdminClient $client) {}

    /**
     * @param  bool  $restock  put the items back into inventory
     *
     * @throws RuntimeException when Shopify refuses — a fulfilled order, most often
     */
    public function cancel(string $orderId, bool $restock = true, string $reason = ''): void
    {
        if (! $this->client->isConnected()) {
            throw new RuntimeException('cancel_not_connected');
        }

        try {
            $response = $this->client->restPost("orders/{$orderId}/cancel.json", [
                'restock' => $restock,
                'reason' => 'customer',
                'email' => false,   // the shop decides what the customer hears, not this
            ]);
        } catch (Throwable $e) {
            SystemLog::error('billing', 'Shopify refused to cancel the order', [
                'shopify_order_id' => $orderId,
                'message' => $e->getMessage(),
            ]);

            throw new RuntimeException('cancel_refused');
        }

        SystemLog::info('billing', 'order cancelled in Shopify', [
            'shopify_order_id' => $orderId,
            'restock' => $restock,
            'reason' => $reason,
            'response' => $response['order']['cancelled_at'] ?? null,
        ]);
    }
}
