<?php

use App\Models\DiscountRule;
use Illuminate\Database\Migrations\Migration;

/**
 * The store's actual discount policy, as Aviad stated it on 2026-08-31:
 * 10% off the recurring order, on everything except product 9991874183472.
 *
 * Written as data rather than left to be typed in, because it is the policy the store has
 * been running on all along — the 10% that was hard-wired into every subscription until it
 * was removed yesterday. Re-entering it by hand on a screen is how a store ends up running
 * on a rule nobody can point to.
 *
 * ONE rule, not two. "10% off the food" and "except this product" are the two halves of a
 * single sentence: a second rule granting 0% to that product would never win anything —
 * the resolver picks the rule worth the MOST money, and a rule worth nothing is worth
 * nothing — so the exclusion has to live inside the rule that would otherwise discount it.
 *
 * The id is registered as BOTH a product and a variant id. The number came from a Shopify
 * admin URL, where the two are indistinguishable at a glance, and listing it in the wrong
 * column would silently discount the very thing this exists to protect. Listing it in both
 * costs nothing: the column it does not belong to simply never matches.
 */
return new class extends Migration
{
    private const EXCLUDED_ID = '9991874183472';

    private const NAME = 'הנחת מנוי';

    public function up(): void
    {
        // Idempotent: a re-run on an environment that already has it must not create a
        // second rule that then competes with the first.
        if (DiscountRule::query()->where('name', self::NAME)->exists()) {
            return;
        }

        DiscountRule::query()->create([
            'name' => self::NAME,
            'is_active' => true,
            'percent' => 10,
            // Line-scoped, and it MUST be: an order-wide 10% would take its cut off the
            // excluded product too, by way of the order total.
            'scope' => DiscountRule::SCOPE_MATCHING_LINES,
            'excluded_product_ids' => [self::EXCLUDED_ID],
            'excluded_variant_ids' => [self::EXCLUDED_ID],
        ]);
    }

    public function down(): void
    {
        DiscountRule::query()->where('name', self::NAME)->delete();
    }
};
