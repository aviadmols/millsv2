<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\DiscountRule;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Support\ShopifyId;

/**
 * What the next order costs, worked out in the open: products, discount, total.
 *
 * The screen used to show one number — the stored total of the Shopify draft — with no way
 * to see where it came from. So when a discount rule changed, or a product was swapped, or
 * the stored figure simply went stale, nobody could tell by looking; the first sign was an
 * invoice with a ₪118 "discount" on it that nobody had granted.
 *
 * Everything here is computed from the store's own prices and the discount rules as they
 * stand right now, so the breakdown answers the two questions an admin actually has while
 * editing an order: which discount is being applied, and what the customer will be charged.
 *
 * It is a PREVIEW, not the billing authority. The money that moves is still the draft total
 * Shopify returns — which is exactly why `matches_stored` matters: when this figure and the
 * stored one disagree, the stored one is stale and the screen says so instead of letting it
 * surface later as a wrong charge.
 */
final class ChargePreview
{
    /**
     * @param  list<array{variant_id: string, quantity: int}>|null  $lines  the lines being
     *                                                                      edited right now; null uses the subscription's own products
     * @return array{
     *     lines: list<array{variant_id: string, title: string, quantity: int, unit: float, total: float, discounted: bool}>,
     *     subtotal: float,
     *     discount_name: ?string,
     *     discount_percent: float,
     *     discount_amount: float,
     *     discount_scope: ?string,
     *     total: float,
     *     stored: ?float,
     *     matches_stored: bool,
     *     unpriced: list<string>
     * }
     */
    public static function for(Subscription $subscription, ?array $lines = null): array
    {
        $lines ??= self::linesFromSubscription($subscription);

        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('shopify_variant_id', array_map(fn (array $l) => (string) $l['variant_id'], $lines))
            ->get()
            ->keyBy(fn (ProductVariant $v) => (string) $v->shopify_variant_id);

        $priced = [];
        $unpriced = [];
        $subtotal = 0.0;

        foreach ($lines as $line) {
            $id = ShopifyId::numeric((string) $line['variant_id']);
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $variant = $variants->get($id);

            if ($variant === null || $variant->price === null) {
                // Named, not silently dropped: a line nobody can price is the one thing on
                // this screen that will make the real charge disagree with the preview.
                $unpriced[] = $id;

                continue;
            }

            $unit = round((float) $variant->price, 2);
            $total = round($unit * $quantity, 2);
            $subtotal += $total;

            $priced[] = [
                'variant_id' => $id,
                'title' => trim(($variant->product?->title ?: '—').' · '.($variant->title ?: $variant->sku ?: $id)),
                'quantity' => $quantity,
                'unit' => $unit,
                'total' => $total,
                'discounted' => false,
            ];
        }

        $subtotal = round($subtotal, 2);

        // The decision is made by the SAME resolver that bills, against these exact lines —
        // a preview computed by its own private rules would be a second opinion, and the
        // one thing worse than no breakdown is a breakdown that disagrees with the charge.
        $decision = DiscountResolver::for($subscription, array_map(
            fn (array $l) => [
                'variantId' => ShopifyId::gid((string) $l['variant_id'], 'ProductVariant'),
                'quantity' => (int) $l['quantity'],
            ],
            $lines,
        ));

        $discountAmount = 0.0;
        $discountedIds = [];

        if ($decision !== null) {
            $percent = (float) $decision['percent'];
            $discountedIds = $decision['scope'] === DiscountRule::SCOPE_MATCHING_LINES
                ? $decision['variant_ids']
                : array_map(fn (array $l) => $l['variant_id'], $priced);

            $base = 0.0;
            foreach ($priced as $i => $line) {
                if (! in_array($line['variant_id'], $discountedIds, true)) {
                    continue;
                }

                $priced[$i]['discounted'] = true;
                $base += $line['total'];
            }

            $discountAmount = round($base * $percent / 100, 2);
        }

        $total = round($subtotal - $discountAmount, 2);
        $stored = $subscription->next_charge_amount === null ? null : round((float) $subscription->next_charge_amount, 2);

        return [
            'lines' => $priced,
            'subtotal' => $subtotal,
            'discount_name' => $decision === null
                ? null
                : ($decision['rule_name'] ?: __('subscriptions.discount_title')),
            'discount_percent' => $decision === null ? 0.0 : (float) $decision['percent'],
            'discount_amount' => $discountAmount,
            'discount_scope' => $decision['scope'] ?? null,
            'total' => $total,
            'stored' => $stored,
            // A one-agora tolerance: the two are computed by different routes and must be
            // allowed to round differently without crying wolf on every screen.
            'matches_stored' => $stored !== null && abs($stored - $total) <= 0.01,
            'unpriced' => $unpriced,
        ];
    }

    /**
     * The subscription's own lines — a hand-edited override if one stands, otherwise the
     * dogs' products. Mirrors DraftOrderService so the preview shops from the same shelf.
     *
     * @return list<array{variant_id: string, quantity: int}>
     */
    private static function linesFromSubscription(Subscription $subscription): array
    {
        if ($subscription->line_items_override !== null) {
            return array_values(array_filter(array_map(
                fn (array $line) => [
                    'variant_id' => ShopifyId::numeric((string) ($line['variant_id'] ?? '')),
                    'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
                ],
                (array) $subscription->line_items_override,
            ), fn (array $line) => $line['variant_id'] !== ''));
        }

        $subscription->loadMissing('dogs');

        $lines = [];

        foreach ($subscription->dogs as $dog) {
            if (($dog->status ?? 'active') !== 'active') {
                continue;
            }

            $variants = array_merge(
                VariantResolver::normalise($dog->selected_variants),
                VariantResolver::normalise($dog->addons_products),
            );

            foreach ($variants as $variantId) {
                $lines[] = [
                    'variant_id' => (string) $variantId,
                    'quantity' => $dog->double_food ? 2 : 1,
                ];
            }
        }

        return $lines;
    }
}
