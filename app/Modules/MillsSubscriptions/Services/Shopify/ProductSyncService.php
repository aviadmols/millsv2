<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ShopifyId;
use Illuminate\Support\Facades\Log;

/**
 * Product + media cache (ARCHITECTURE.md §1c). Pulls products/variants from
 * Shopify (incl. featuredImage.url) into the local cache so hot paths never call
 * Shopify live. Mills fields (grams, pack_size, flavor_key) are parsed from the
 * SKU, mirroring v1's ProductCatalogService.
 */
class ProductSyncService
{
    private const PAGE_QUERY = <<<'GQL'
    query($cursor: String) {
      products(first: 50, after: $cursor) {
        pageInfo { hasNextPage endCursor }
        nodes {
          id title handle status tags
          featuredImage { url }
          variants(first: 100) {
            nodes { id title sku price image { url } }
          }
        }
      }
    }
    GQL;

    public function __construct(private readonly ShopifyAdminClient $client) {}

    /** Full refresh of the local product cache. Returns the number of products upserted. */
    public function refreshAll(): int
    {
        if (! $this->client->isConnected()) {
            Log::warning('shopify.products.not_connected');

            return 0;
        }

        $count = 0;
        $cursor = null;

        do {
            $result = $this->client->graphql(self::PAGE_QUERY, ['cursor' => $cursor]);
            $page = $result['data']['products'] ?? null;
            if ($page === null) {
                break;
            }

            foreach ($page['nodes'] ?? [] as $node) {
                $this->upsertProduct($node);
                $count++;
            }

            $cursor = ($page['pageInfo']['hasNextPage'] ?? false) ? ($page['pageInfo']['endCursor'] ?? null) : null;
        } while ($cursor !== null);

        return $count;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    public function upsertProduct(array $node): void
    {
        $productId = ShopifyId::numeric((string) ($node['id'] ?? ''));
        if ($productId === '') {
            return;
        }

        $product = Product::query()->updateOrCreate(
            ['shopify_product_id' => $productId],
            [
                'title' => (string) ($node['title'] ?? ''),
                'handle' => $node['handle'] ?? null,
                'status' => strtolower((string) ($node['status'] ?? 'active')),
                'image_url' => $node['featuredImage']['url'] ?? null,
                'tags' => $node['tags'] ?? [],
                'synced_at' => now(),
            ],
        );

        $position = 0;
        foreach ($node['variants']['nodes'] ?? [] as $variant) {
            $variantId = ShopifyId::numeric((string) ($variant['id'] ?? ''));
            if ($variantId === '') {
                continue;
            }

            $sku = (string) ($variant['sku'] ?? '');
            ProductVariant::query()->updateOrCreate(
                ['shopify_variant_id' => $variantId],
                [
                    'product_id' => $product->id,
                    'title' => $variant['title'] ?? null,
                    'sku' => $sku !== '' ? $sku : null,
                    'price' => $variant['price'] ?? null,
                    'position' => $position++,
                    'image_url' => $variant['image']['url'] ?? ($node['featuredImage']['url'] ?? null),
                    'grams' => $this->parseGrams($sku),
                    'pack_size' => $this->parsePackSize($sku),
                    'flavor_key' => $this->parseFlavorKey($sku),
                    'synced_at' => now(),
                ],
            );
        }
    }

    private function parseGrams(string $sku): ?int
    {
        return preg_match('/(\d{3,5})\s*g/i', $sku, $m) === 1 ? (int) $m[1] : null;
    }

    private function parsePackSize(string $sku): ?int
    {
        if (preg_match('/x\s*(\d{1,3})/i', $sku, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    private function parseFlavorKey(string $sku): ?string
    {
        $parts = preg_split('/[-_\s]+/', trim($sku)) ?: [];

        return $parts[0] !== '' ? strtolower((string) ($parts[0] ?? '')) : null;
    }
}
