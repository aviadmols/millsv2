<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\ProductVariant;
use App\Support\ShopifyId;
use Illuminate\Support\Collection;

/**
 * What a customer may put in their upcoming order, and which of the two kinds it is.
 *
 * Both kinds repeat every order (Aviad, 2026-11-12) — they only live in different places:
 * FOOD on the dog (selected_variants / double_food, quantity 1 or 2), EXTRAS — tasting
 * boxes, snacks, toys — on the subscription (subscriptions.recurring_addons, any quantity).
 *
 * The line between them is the pack size parsed from the SKU (15 or 30 daily packs): every
 * food bag has one, and nothing else in the catalog does — tasting boxes and snacks are
 * typed "מזון" too, so the product type alone cannot tell them apart (checked against the
 * live catalog, 2026-11-12: 450 food variants with a pack, 21 "מזון" extras and 120
 * accessories without one).
 */
final class OrderCatalog
{
    public const KIND_FOOD = 'food';

    public const KIND_ADDON = 'addon';

    public static function kind(ProductVariant $variant): string
    {
        return $variant->pack_size !== null ? self::KIND_FOOD : self::KIND_ADDON;
    }

    /**
     * Variants a customer can pick from: an active product, in stock, with a price, and a
     * product type — the untyped products are the shipping line and the welcome flyer.
     * The hidden "מנוי מתחדש" product is untyped as well, so it never appears here; a dog
     * that already eats one of its variants keeps it.
     *
     * @return Collection<int, ProductVariant>
     */
    public static function selectable(): Collection
    {
        return ProductVariant::query()
            ->with('product')
            ->where('available', true)
            ->whereNotNull('price')
            ->whereHas('product', fn ($q) => $q->where('status', 'active')->whereNotNull('product_type')->where('product_type', '!=', ''))
            ->orderBy('product_id')
            ->orderBy('position')
            ->get();
    }

    /**
     * Resolve requested ids to selectable variants of ONE kind, or null when any id is not
     * one — the caller refuses the whole request rather than saving part of it.
     *
     * @param  list<string>  $ids
     * @return Collection<string, ProductVariant>|null keyed by numeric variant id
     */
    public static function resolve(array $ids, string $kind): ?Collection
    {
        $numeric = array_values(array_unique(array_map(fn ($id) => ShopifyId::numeric((string) $id), $ids)));

        $found = self::selectable()
            ->filter(fn (ProductVariant $v) => in_array((string) $v->shopify_variant_id, $numeric, true))
            ->keyBy(fn (ProductVariant $v) => (string) $v->shopify_variant_id);

        if ($found->count() !== count($numeric)) {
            return null;
        }

        return $found->every(fn (ProductVariant $v) => self::kind($v) === $kind) ? $found : null;
    }

    /** @return array<string, mixed> one catalog entry, priced in AGOROT like every storefront price */
    public static function present(ProductVariant $variant): array
    {
        return [
            'variant_id' => (string) $variant->shopify_variant_id,
            'kind' => self::kind($variant),
            'product_id' => (string) ($variant->product?->shopify_product_id ?? ''),
            'product_title' => (string) ($variant->product?->title ?? ''),
            'variant_title' => (string) ($variant->title ?? ''),
            'name' => VariantResolver::label($variant),
            'price' => (int) round(((float) $variant->price) * 100),
            'image' => $variant->image_url ?: $variant->product?->image_url,
            'pack_size' => $variant->pack_size,
            'grams' => $variant->grams,
        ];
    }
}
