<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\ProductVariant;
use App\Support\ShopifyId;
use Illuminate\Support\Collection;

/**
 * Turns a dog's stored variant ids back into real products.
 *
 * Nothing else in the app did this, which is why a subscription's products were
 * invisible even when they were stored.
 *
 * The ids are NOT stored consistently: ProductSyncService writes numeric ids
 * (`66514426462512`), while data coming from Shopify metaobjects and orders is
 * GID-shaped (`gid://shopify/ProductVariant/66514426462512`). Everything is
 * normalised through ShopifyId::numeric() before lookup — a naive `whereIn` on the
 * raw values silently matches nothing.
 */
final class VariantResolver
{
    /**
     * Resolve ids to variants, preserving the given order. Unknown ids are dropped.
     *
     * @return Collection<int, ProductVariant>
     */
    public static function resolve(mixed $ids): Collection
    {
        $numeric = self::normalise($ids);

        if ($numeric === []) {
            return collect();
        }

        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('shopify_variant_id', $numeric)
            ->get()
            ->keyBy(fn (ProductVariant $v) => (string) $v->shopify_variant_id);

        return collect($numeric)
            ->map(fn (string $id) => $variants->get($id))
            ->filter()
            ->values();
    }

    /**
     * Human labels for the admin: product · portion · pack · price.
     * Ids we cannot resolve are still reported — silence would hide a broken link.
     *
     * @return list<string>
     */
    public static function labels(mixed $ids): array
    {
        $numeric = self::normalise($ids);
        if ($numeric === []) {
            return [];
        }

        $resolved = self::resolve($ids)->keyBy(fn (ProductVariant $v) => (string) $v->shopify_variant_id);

        $labels = [];
        foreach ($numeric as $id) {
            $variant = $resolved->get($id);

            if ($variant === null) {
                $labels[] = __('subscriptions.unknown_variant', ['id' => $id]);

                continue;
            }

            $labels[] = self::label($variant);
        }

        return $labels;
    }

    /**
     * The variants as renderable lines — image, name, portion, pack, price.
     *
     * An id that no longer resolves is NOT dropped. A subscription silently missing a
     * product it is being billed for is exactly the kind of thing that must be loud, so
     * the line still appears, carrying a warning.
     *
     * @return list<array<string, mixed>>
     */
    public static function lines(mixed $ids): array
    {
        $numeric = self::normalise($ids);
        if ($numeric === []) {
            return [];
        }

        $resolved = self::resolve($ids)->keyBy(fn (ProductVariant $v) => (string) $v->shopify_variant_id);

        $lines = [];

        foreach ($numeric as $id) {
            $variant = $resolved->get($id);

            if ($variant === null) {
                $lines[] = [
                    'title' => __('subscriptions.unknown_variant', ['id' => $id]),
                    'image_url' => null,
                    'sku' => null,
                    'grams' => null,
                    'pack_size' => null,
                    'price' => null,
                    'warning' => __('subscriptions.variant_missing'),
                ];

                continue;
            }

            $lines[] = [
                'title' => $variant->product?->title ?? '—',
                'image_url' => $variant->image_url,
                'sku' => $variant->sku,
                'grams' => $variant->grams,
                'pack_size' => $variant->pack_size,
                'price' => $variant->price,
                'quantity' => 1,
            ];
        }

        return $lines;
    }

    /** One variant, described the way an admin needs to read it. */
    public static function label(ProductVariant $variant): string
    {
        $parts = [$variant->product?->title ?? '—'];

        if ($variant->grams !== null) {
            $parts[] = $variant->grams.' '.__('subscriptions.grams_per_day');
        }

        if ($variant->pack_size !== null) {
            $parts[] = $variant->pack_size.' '.__('subscriptions.day_pack');
        }

        if ($variant->price !== null) {
            $parts[] = '₪'.number_format((float) $variant->price, 2);
        }

        return implode(' · ', $parts);
    }

    /**
     * @return list<string> numeric ids, de-duplicated, order preserved
     */
    public static function normalise(mixed $ids): array
    {
        if (is_string($ids)) {
            $decoded = json_decode($ids, true);
            $ids = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($ids)) {
            return [];
        }

        $out = [];
        foreach ($ids as $id) {
            if (is_array($id)) {
                $id = $id['id'] ?? null;
            }
            if ($id === null || $id === '') {
                continue;
            }

            $numeric = ShopifyId::numeric((string) $id);
            if ($numeric !== '') {
                $out[] = $numeric;
            }
        }

        return array_values(array_unique($out));
    }
}
