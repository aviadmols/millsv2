<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\Customer;
use App\Models\SystemLog;
use App\Support\ShopifyId;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The customer's REAL orders, read from Shopify.
 *
 * v1 never stored order lines locally, so the only truthful source of "what did
 * this customer actually receive" is Shopify itself. `read_orders` is granted, so
 * this is a straight read — cached briefly because the admin renders it on every
 * page view and a Shopify round-trip per open is not acceptable.
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
              variant { id }
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
            "shopify.orders.{$shopifyId}.{$limit}",
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
     * The variant ids on the customer's most recent PAID order — i.e. what they are
     * actually being sent today. This is the authoritative source for repairing a
     * subscription whose product selection was never migrated.
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

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function present(array $node): array
    {
        $lineItems = [];
        foreach ($node['lineItems']['nodes'] ?? [] as $item) {
            $lineItems[] = [
                'title' => $item['title'] ?? null,
                'quantity' => (int) ($item['quantity'] ?? 1),
                'sku' => $item['sku'] ?? null,
                'variant_id' => ShopifyId::numeric((string) ($item['variant']['id'] ?? '')) ?: null,
                'price' => $item['originalUnitPriceSet']['shopMoney']['amount'] ?? null,
            ];
        }

        return [
            'id' => ShopifyId::numeric((string) ($node['id'] ?? '')),
            'name' => $node['name'] ?? null,                       // e.g. #68772
            'created_at' => $node['createdAt'] ?? null,
            'financial_status' => $node['displayFinancialStatus'] ?? null,
            'fulfillment_status' => $node['displayFulfillmentStatus'] ?? null,
            'total' => $node['totalPriceSet']['shopMoney']['amount'] ?? null,
            'currency' => $node['totalPriceSet']['shopMoney']['currencyCode'] ?? 'ILS',
            'line_items' => $lineItems,
        ];
    }
}
