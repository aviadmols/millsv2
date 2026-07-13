<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\Customer;
use App\Models\ShopifyConnection;
use App\Models\SystemLog;
use App\Modules\MillsSubscriptions\Support\VariantResolver;
use App\Support\ShopifyId;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The customer's REAL orders, read from Shopify.
 *
 * v1 never stored order lines locally, so the only truthful source of "what did this
 * customer actually receive" is Shopify itself. `read_orders` is granted, so this is
 * a straight read — cached briefly because the admin renders it on every page view.
 *
 * Each line item is enriched from the local product cache with the product IMAGE, so
 * an admin sees what was shipped rather than a wall of SKUs. The order also carries a
 * ready-made `admin_url`: building that link in the view is how it ended up pointing
 * every order at /orders/1 (the closure was handed the subscription, not the order).
 */
class OrderHistoryService
{
    private const CACHE_TTL_SECONDS = 300;

    private const QUERY = <<<'GQL'
    query($q: String!, $first: Int!) {
      orders(first: $first, query: $q, sortKey: CREATED_AT, reverse: true) {
        nodes {
          id
          name
          createdAt
          displayFinancialStatus
          displayFulfillmentStatus
          totalPriceSet { shopMoney { amount currencyCode } }
          lineItems(first: 25) {
            nodes {
              title
              quantity
              sku
              variant { id image { url } }
              image { url }
              originalUnitPriceSet { shopMoney { amount } }
            }
          }
        }
      }
    }
    GQL;

    public function __construct(private readonly ShopifyAdminClient $client) {}

    /**
     * @return list<array<string, mixed>> newest first; empty when disconnected or unknown
     */
    public function forCustomer(Customer $customer, int $limit = 10): array
    {
        $shopifyId = (string) ($customer->shopify_customer_id ?? '');

        if ($shopifyId === '' || ! $this->client->isConnected()) {
            return [];
        }

        return Cache::remember(
            "shopify.orders.{$shopifyId}.{$limit}.v2",
            self::CACHE_TTL_SECONDS,
            function () use ($shopifyId, $limit, $customer) {
                try {
                    $result = $this->client->graphql(self::QUERY, [
                        'q' => 'customer_id:'.$shopifyId,
                        'first' => $limit,
                    ]);

                    $nodes = $result['data']['orders']['nodes'] ?? null;

                    if ($nodes === null) {
                        SystemLog::warning('shopify', 'order history query returned nothing', [
                            'errors' => $result['errors'] ?? null,
                        ], ['customer_id' => $customer->id]);

                        return [];
                    }

                    return array_map(fn (array $node) => $this->present($node), $nodes);
                } catch (Throwable $e) {
                    SystemLog::error('shopify', 'order history fetch failed', [
                        'message' => $e->getMessage(),
                    ], ['customer_id' => $customer->id]);

                    return [];
                }
            },
        );
    }

    /**
     * The variant ids on the customer's most recent PAID order — what they are actually
     * being sent today. The authoritative source for repairing a subscription whose
     * product selection was never migrated.
     *
     * @return list<string> numeric variant ids
     */
    public function latestPaidVariantIds(Customer $customer): array
    {
        foreach ($this->forCustomer($customer, 10) as $order) {
            if (($order['financial_status'] ?? '') !== 'PAID') {
                continue;
            }

            $ids = [];
            foreach ($order['line_items'] as $item) {
                if (! empty($item['variant_id'])) {
                    $ids[] = $item['variant_id'];
                }
            }

            if ($ids !== []) {
                return array_values(array_unique($ids));
            }
        }

        return [];
    }

    /** Drop the cached history — call after anything that changes the customer's orders. */
    public function forget(Customer $customer): void
    {
        $shopifyId = (string) ($customer->shopify_customer_id ?? '');
        if ($shopifyId === '') {
            return;
        }

        foreach ([5, 10, 25] as $limit) {
            Cache::forget("shopify.orders.{$shopifyId}.{$limit}.v2");
        }
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function present(array $node): array
    {
        $orderId = ShopifyId::numeric((string) ($node['id'] ?? ''));

        $lineItems = [];
        foreach ($node['lineItems']['nodes'] ?? [] as $item) {
            $variantId = ShopifyId::numeric((string) ($item['variant']['id'] ?? '')) ?: null;

            $lineItems[] = [
                'title' => $item['title'] ?? '—',
                'quantity' => (int) ($item['quantity'] ?? 1),
                'sku' => $item['sku'] ?? null,
                'variant_id' => $variantId,
                'price' => $item['originalUnitPriceSet']['shopMoney']['amount'] ?? null,
                // Shopify's own image first; fall back to the local cache, which is
                // populated even for products whose order line lost its image.
                'image_url' => $item['variant']['image']['url']
                    ?? $item['image']['url']
                    ?? $this->cachedImage($variantId),
            ];
        }

        return [
            'id' => $orderId,
            'name' => $node['name'] ?? null,                        // #68772
            'admin_url' => $orderId !== '' ? self::adminUrl('orders', $orderId) : null,
            'created_at' => $node['createdAt'] ?? null,
            'financial_status' => $node['displayFinancialStatus'] ?? null,
            'fulfillment_status' => $node['displayFulfillmentStatus'] ?? null,
            'total' => $node['totalPriceSet']['shopMoney']['amount'] ?? null,
            'currency' => $node['totalPriceSet']['shopMoney']['currencyCode'] ?? 'ILS',
            'line_items' => $lineItems,
        ];
    }

    private function cachedImage(?string $variantId): ?string
    {
        if ($variantId === null) {
            return null;
        }

        return VariantResolver::resolve([$variantId])->first()?->image_url;
    }

    /** A link straight into the Shopify admin for an order or draft order. */
    public static function adminUrl(string $resource, ?string $id): ?string
    {
        $numeric = ShopifyId::numeric((string) $id);
        if ($numeric === '') {
            return null;
        }

        $domain = (string) (ShopifyConnection::current()?->shop_domain ?: config('shopify.shop_domain'));
        $handle = str_replace('.myshopify.com', '', $domain) ?: 'millsforpets';

        return "https://admin.shopify.com/store/{$handle}/{$resource}/{$numeric}";
    }
}
