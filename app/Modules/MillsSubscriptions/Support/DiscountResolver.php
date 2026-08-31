<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\DiscountRule;
use App\Models\ProductVariant;
use App\Models\Subscription;
use App\Support\ShopifyId;
use Illuminate\Support\Collection;

/**
 * Which discount a subscription gets, and off what.
 *
 * Rules are evaluated against the order that is about to be built — the variants actually
 * in it, their products' tags, their pack sizes, and the subscription's frequency. Every
 * matching rule is costed IN SHEKELS and the most valuable one wins.
 *
 * Costing rather than comparing percentages is the whole point of the "highest wins"
 * choice: 15% off one ₪40 add-on is not a better deal than 5% off a ₪700 order, and a
 * comparison of the two numbers 15 and 5 says it is. The customer should get whichever
 * actually takes more money off.
 *
 * A discount set BY HAND on the subscription outranks every rule. Someone typed that
 * number for a reason — usually a promise made to a specific customer on the phone — and a
 * rules engine that silently overwrites it turns a kept promise into a broken one.
 */
final class DiscountResolver
{
    /**
     * @param  list<array{variantId: string, quantity: int}>  $lineItems
     * @return array{percent: float, scope: string, rule_id: ?int, rule_name: ?string, variant_ids: list<string>, value: float}|null
     */
    public static function for(Subscription $subscription, array $lineItems): ?array
    {
        $manual = (float) ($subscription->discount_percent ?? 0);

        if ($manual > 0) {
            return [
                'percent' => $manual,
                'scope' => DiscountRule::SCOPE_ORDER,
                'rule_id' => null,
                'rule_name' => null,
                'variant_ids' => [],
                'value' => 0.0,   // never compared against anything; it simply wins
            ];
        }

        $rules = DiscountRule::query()->where('is_active', true)->get();

        if ($rules->isEmpty() || $lineItems === []) {
            return null;
        }

        $lines = self::priceLines($lineItems);

        if ($lines === []) {
            // We cannot price the order, so we cannot honestly cost a rule against it.
            // Charging full price is the safe direction to be wrong in; the alternative is
            // giving away a discount whose size we are guessing at.
            return null;
        }

        $best = null;

        foreach ($rules as $rule) {
            $decision = self::evaluate($rule, $subscription, $lines);

            if ($decision === null) {
                continue;
            }

            if ($best === null
                || $decision['value'] > $best['value']
                || ($decision['value'] === $best['value'] && $decision['priority'] > $best['priority'])) {
                $best = $decision;
            }
        }

        if ($best === null) {
            return null;
        }

        unset($best['priority']);

        return $best;
    }

    /**
     * @param  list<array{variant_id: string, quantity: int, price: float, tags: list<string>, pack_size: ?int, product_id: string}>  $lines
     * @return array{percent: float, scope: string, rule_id: int, rule_name: string, variant_ids: list<string>, value: float, priority: int}|null
     */
    private static function evaluate(DiscountRule $rule, Subscription $subscription, array $lines): ?array
    {
        if ($rule->frequency_months !== null && (int) $rule->frequency_months !== (int) $subscription->frequency_months) {
            return null;
        }

        $matching = self::matchingLines($rule, $lines);

        // A rule WITH product conditions that nothing matched does not apply at all.
        if ($rule->hasProductConditions() && $matching === []) {
            return null;
        }

        $percent = (float) $rule->percent;

        /*
         * `matching_lines` on a rule that names no products has nothing to narrow to, so it
         * would take 0 off and look broken on screen. Treated as order-wide: that is what
         * the person meant when they set a percentage and no product condition.
         */
        $scope = ($rule->scope === DiscountRule::SCOPE_MATCHING_LINES && $rule->hasProductConditions())
            ? DiscountRule::SCOPE_MATCHING_LINES
            : DiscountRule::SCOPE_ORDER;

        $base = $scope === DiscountRule::SCOPE_MATCHING_LINES
            ? self::sum($matching)
            : self::sum($lines);

        return [
            'percent' => $percent,
            'scope' => $scope,
            'rule_id' => (int) $rule->id,
            'rule_name' => (string) $rule->name,
            'variant_ids' => array_values(array_map(fn (array $l) => $l['variant_id'], $matching)),
            'value' => round($base * $percent / 100, 2),
            'priority' => (int) $rule->priority,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private static function matchingLines(DiscountRule $rule, array $lines): array
    {
        /*
         * Exclusions come off FIRST and are absolute: a product named here is never
         * discounted by this rule, however well it matches everything else. That ordering
         * is the whole point — "10% off the food but not the treat" must not become 10% off
         * the treat as well because the treat also happens to carry the discounted tag.
         */
        if ($rule->hasExclusions()) {
            $excludedProducts = array_map('strval', (array) ($rule->excluded_product_ids ?? []));
            $excludedVariants = array_map('strval', (array) ($rule->excluded_variant_ids ?? []));

            $lines = array_values(array_filter($lines, fn (array $line) => ! in_array($line['variant_id'], $excludedVariants, true)
                && ! in_array($line['product_id'], $excludedProducts, true)));
        }

        // Nothing said about WHICH products, beyond what was excluded: everything that is
        // left is what the rule applies to.
        if (! $rule->hasInclusions()) {
            return $lines;
        }

        $productIds = array_map('strval', (array) ($rule->product_ids ?? []));
        $variantIds = array_map('strval', (array) ($rule->variant_ids ?? []));
        $tags = array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) ($rule->tags ?? []));
        $packSizes = array_map('intval', (array) ($rule->pack_sizes ?? []));

        return array_values(array_filter($lines, function (array $line) use ($productIds, $variantIds, $tags, $packSizes) {
            // OR within the product conditions: any one of them is enough to pull a line in.
            if ($variantIds !== [] && in_array($line['variant_id'], $variantIds, true)) {
                return true;
            }

            if ($productIds !== [] && in_array($line['product_id'], $productIds, true)) {
                return true;
            }

            if ($packSizes !== [] && $line['pack_size'] !== null && in_array((int) $line['pack_size'], $packSizes, true)) {
                return true;
            }

            return $tags !== [] && array_intersect($tags, $line['tags']) !== [];
        }));
    }

    /** @param list<array<string, mixed>> $lines */
    private static function sum(array $lines): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $total += ((float) $line['price']) * ((int) $line['quantity']);
        }

        return round($total, 2);
    }

    /**
     * The order's lines, priced and described from the local product cache.
     *
     * A variant we have never synced is skipped rather than priced at zero: a line the
     * engine cannot see is a line it must not claim to have discounted.
     *
     * @param  list<array{variantId: string, quantity: int}>  $lineItems
     * @return list<array{variant_id: string, quantity: int, price: float, tags: list<string>, pack_size: ?int, product_id: string}>
     */
    private static function priceLines(array $lineItems): array
    {
        $ids = array_values(array_filter(array_map(
            fn (array $item) => ShopifyId::numeric((string) ($item['variantId'] ?? '')),
            $lineItems,
        )));

        if ($ids === []) {
            return [];
        }

        /** @var Collection<string, ProductVariant> $variants */
        $variants = ProductVariant::query()
            ->with('product')
            ->whereIn('shopify_variant_id', $ids)
            ->get()
            ->keyBy(fn (ProductVariant $v) => (string) $v->shopify_variant_id);

        $lines = [];

        foreach ($lineItems as $item) {
            $id = ShopifyId::numeric((string) ($item['variantId'] ?? ''));
            $variant = $id === '' ? null : $variants->get($id);

            if ($variant === null || $variant->price === null) {
                continue;
            }

            $lines[] = [
                'variant_id' => $id,
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'price' => (float) $variant->price,
                'pack_size' => $variant->pack_size,
                'product_id' => (string) ($variant->product?->shopify_product_id ?? ''),
                'tags' => array_map(
                    fn ($t) => mb_strtolower(trim((string) $t)),
                    (array) ($variant->product?->tags ?? []),
                ),
            ];
        }

        return $lines;
    }
}
