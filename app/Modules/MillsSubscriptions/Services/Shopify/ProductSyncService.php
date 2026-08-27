<?php

namespace App\Modules\MillsSubscriptions\Services\Shopify;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\ShopifyId;
use Illuminate\Support\Facades\Log;

/**
 * Product + media cache (ARCHITECTURE.md §1c). Pulls products/variants from
 * Shopify (incl. featuredImage.url) into the local cache so hot paths never call
 * Shopify live.
 *
 * The SKU is the catalog's real schema. A subscription SKU reads:
 *
 *     SF30 - אריזה יומית של 79 גרם
 *     ^^ ^^                  ^^
 *     |  |                   daily grams  → what the recommender matches on
 *     |  pack size (15 | 30 daily pouches; 30 = one flavour, 15 = two flavours)
 *     flavour code
 *
 * Grams are parsed exactly the way the storefront does it (the digits of the
 * segment after the first "-", see THEME/assets/quiz-api.js findBestVariantsForProduct),
 * so the server and the theme can never disagree about a dog's portion size.
 */
class ProductSyncService
{
    /**
     * The fields every product read asks for. Shared by both queries on purpose: a
     * one-product refresh that pulled a smaller shape than the full sweep would write
     * a half-filled row over a complete one.
     */
    private const PRODUCT_FIELDS = <<<'GQL'
      id title handle status tags productType
      featuredImage { url }
      multiplier: metafield(namespace: "product", key: "multiplier") { value }
      collections(first: 10) { nodes { title } }
      variants(first: 250) {
        pageInfo { hasNextPage endCursor }
        nodes { id title sku price availableForSale image { url } }
      }
    GQL;

    /**
     * The page beyond a product's first 250 variants. The hidden pricing product
     * ("מנוי מתחדש") grows a variant per flavor×size×price, so it sails past any
     * fixed cap — and every variant beyond the cap was invisible to the cache,
     * which is how a real dog's food rendered as "וריאנט לא מזוהה".
     */
    private const MORE_VARIANTS_QUERY = <<<'GQL'
    query($id: ID!, $cursor: String!) {
      product(id: $id) {
        variants(first: 250, after: $cursor) {
          pageInfo { hasNextPage endCursor }
          nodes { id title sku price availableForSale image { url } }
        }
      }
    }
    GQL;

    private const PAGE_QUERY = 'query($cursor: String) { products(first: 50, after: $cursor) { pageInfo { hasNextPage endCursor } nodes {'
        .self::PRODUCT_FIELDS
        .'} } }';

    private const ONE_QUERY = 'query($id: ID!) { product(id: $id) {'
        .self::PRODUCT_FIELDS
        .'} }';

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
     * Refresh ONE product — what a `products/*` webhook actually reports.
     *
     * Re-reading the whole catalogue for a single price change took 20+ seconds per webhook
     * and put a full sweep of Shopify behind every edit an operator makes; a single network
     * blip anywhere in that sweep failed the job. Returns false when Shopify no longer has
     * the product (deleted between the webhook and this read) — the caller has nothing to do
     * about that, so it is not an error.
     */
    public function refreshOne(string $productId): bool
    {
        if (! $this->client->isConnected()) {
            Log::warning('shopify.products.not_connected');

            return false;
        }

        $numericId = ShopifyId::numeric($productId);
        if ($numericId === '') {
            return false;
        }

        $result = $this->client->graphql(self::ONE_QUERY, ['id' => 'gid://shopify/Product/'.$numericId]);
        $node = $result['data']['product'] ?? null;

        if (! is_array($node)) {
            Log::info('shopify.products.not_found', ['product_id' => $numericId]);

            return false;
        }

        $this->upsertProduct($node);

        return true;
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
                'multiplier' => self::multiplier($node),
                'product_type' => $node['productType'] ?? null,
                'collections' => array_column($node['collections']['nodes'] ?? [], 'title'),
                'synced_at' => now(),
            ],
        );

        $position = 0;
        $this->upsertVariants($product, (array) ($node['variants']['nodes'] ?? []), $node, $position);

        // Beyond the first page: follow the cursor until the product runs out of
        // variants. Without this, everything past the cap simply did not exist here.
        $pageInfo = (array) (data_get($node, 'variants.pageInfo') ?? []);
        while (($pageInfo['hasNextPage'] ?? false) && ($cursor = (string) ($pageInfo['endCursor'] ?? '')) !== '') {
            $result = $this->client->graphql(self::MORE_VARIANTS_QUERY, [
                'id' => 'gid://shopify/Product/'.$productId,
                'cursor' => $cursor,
            ]);

            $page = (array) (data_get($result, 'data.product.variants') ?? []);
            if (($page['nodes'] ?? []) === []) {
                break;
            }

            $this->upsertVariants($product, (array) $page['nodes'], $node, $position);
            $pageInfo = (array) ($page['pageInfo'] ?? []);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $variants
     * @param  array<string, mixed>  $node  the product node (for the fallback image)
     */
    private function upsertVariants(Product $product, array $variants, array $node, int &$position): void
    {
        foreach ($variants as $variant) {
            $variant = (array) $variant;
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
                    'available' => (bool) ($variant['availableForSale'] ?? true),
                    'position' => $position++,
                    'image_url' => $variant['image']['url'] ?? ($node['featuredImage']['url'] ?? null),
                    'grams' => self::parseGrams($sku),
                    'pack_size' => self::parsePackSize($sku),
                    'flavor_key' => self::parseFlavorKey($sku),
                    'synced_at' => now(),
                ],
            );
        }
    }

    /**
     * kcal per gram for this food. The recommender divides the dog's daily calories
     * by it to get the daily grams. Shopify holds it as the `product.multiplier`
     * metafield; an absent or nonsensical value falls back to 1, exactly as the
     * storefront does.
     *
     * @param  array<string, mixed>  $node
     */
    public static function multiplier(array $node): float
    {
        $value = (float) ($node['multiplier']['value'] ?? 0);

        return $value > 0 ? $value : 1.0;
    }

    /**
     * Daily grams. Primary rule is the storefront's: take the segment after the
     * first "-" and keep its digits — `SF30 - אריזה יומית של 79 גרם` → 79. The
     * Hebrew fallback catches SKUs that omit the dash.
     */
    public static function parseGrams(string $sku): ?int
    {
        $sku = trim($sku);
        if ($sku === '') {
            return null;
        }

        $parts = explode('-', $sku, 2);
        if (isset($parts[1])) {
            $digits = preg_replace('/[^0-9]/', '', $parts[1]);
            if ($digits !== '' && $digits !== null) {
                return (int) $digits;
            }
        }

        return preg_match('/(\d{2,4})\s*(?:גרם|גר|g)\b/u', $sku, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * Pack size in daily pouches — the digits bound to the flavour code (`SF30` → 30).
     * Anchored to the prefix on purpose: the storefront's substring match would read
     * "30" out of a 130-gram SKU.
     */
    public static function parsePackSize(string $sku): ?int
    {
        return preg_match('/^[A-Za-z]+(15|30)\b/u', trim($sku), $m) === 1 ? (int) $m[1] : null;
    }

    /** Flavour code — the letters only, so `SF30` and `SF15` are the same flavour. */
    public static function parseFlavorKey(string $sku): ?string
    {
        return preg_match('/^([A-Za-z]+)/', trim($sku), $m) === 1 ? strtolower($m[1]) : null;
    }
}
