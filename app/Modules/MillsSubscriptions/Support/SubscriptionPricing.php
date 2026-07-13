<?php

namespace App\Modules\MillsSubscriptions\Support;

use App\Models\Subscription;

/**
 * What a subscription costs per cycle.
 *
 * The authoritative number is the upcoming order's total from Shopify — it is the order
 * that will actually be placed, with whatever shipping and tax the store applies (the
 * real orders bill ₪182.90 where the product alone is ₪171.00, so a bare product sum
 * would undercharge every single cycle).
 *
 * The local sum below exists only as a floor: if Shopify cannot be reached we must never
 * charge a made-up number, so a caller that has no stored total is told so explicitly
 * rather than being handed a plausible-looking guess.
 */
final class SubscriptionPricing
{
    /**
     * The amount to charge, or null when we genuinely do not know.
     *
     * A null here MUST stop the charge. Guessing is how a customer gets billed the wrong
     * amount, and money is the one thing this system may not be creative about.
     */
    public static function amount(Subscription $subscription): ?float
    {
        if ($subscription->next_charge_amount !== null && (float) $subscription->next_charge_amount > 0) {
            return (float) $subscription->next_charge_amount;
        }

        // Legacy import path: some rows carried the price in meta.
        $meta = (array) ($subscription->meta ?? []);
        $metaPrice = (float) ($meta['price'] ?? 0);

        return $metaPrice > 0 ? $metaPrice : null;
    }

    /**
     * The sum of the subscription's products from the LOCAL cache — products only, no
     * shipping and no tax. Shown next to the real total so a discrepancy is visible;
     * never used to charge.
     */
    public static function productsSubtotal(Subscription $subscription): float
    {
        $subscription->loadMissing('dogs');

        $total = 0.0;

        foreach ($subscription->dogs as $dog) {
            if (($dog->status ?? 'active') !== 'active') {
                continue;
            }

            $quantity = $dog->double_food ? 2 : 1;

            $variants = VariantResolver::resolve(array_merge(
                VariantResolver::normalise($dog->selected_variants),
                VariantResolver::normalise($dog->addons_products),
            ));

            foreach ($variants as $variant) {
                $total += ((float) $variant->price) * $quantity;
            }
        }

        return round($total, 2);
    }
}
