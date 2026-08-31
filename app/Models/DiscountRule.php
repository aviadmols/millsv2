<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One discount rule for the recurring cycle: conditions in, a percentage out.
 *
 * Conditions are ANDed across the groups that are filled in and ORed within a group. A
 * group left empty is NOT a condition — a rule with nothing set matches everything, which
 * is how a plain store-wide discount is expressed.
 *
 * @property string $scope 'order' | 'matching_lines'
 */
class DiscountRule extends Model
{
    /** A percentage off the whole order — one line on the Shopify draft. */
    public const SCOPE_ORDER = 'order';

    /** A percentage off only the lines that matched the product conditions. */
    public const SCOPE_MATCHING_LINES = 'matching_lines';

    protected $fillable = [
        'name', 'is_active', 'percent', 'scope',
        'frequency_months', 'product_ids', 'variant_ids', 'tags', 'pack_sizes',
        'excluded_product_ids', 'excluded_variant_ids', 'priority',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'percent' => 'decimal:2',
            'frequency_months' => 'integer',
            'priority' => 'integer',
            'product_ids' => 'array',
            'variant_ids' => 'array',
            'tags' => 'array',
            'pack_sizes' => 'array',
            'excluded_product_ids' => 'array',
            'excluded_variant_ids' => 'array',
        ];
    }

    /** Does the rule name the products it applies TO (as opposed to the ones it skips)? */
    public function hasInclusions(): bool
    {
        return ! empty($this->product_ids)
            || ! empty($this->variant_ids)
            || ! empty($this->tags)
            || ! empty($this->pack_sizes);
    }

    /** Products this rule must never discount, whatever else it matches. */
    public function hasExclusions(): bool
    {
        return ! empty($this->excluded_product_ids) || ! empty($this->excluded_variant_ids);
    }

    /**
     * True when this rule says nothing about which products it applies to.
     *
     * It matters for `matching_lines`: a rule with no product condition has no "matching
     * lines" to discount, so it would silently take nothing off. The resolver treats that
     * as an order-wide discount rather than a rule that quietly does nothing.
     */
    public function hasProductConditions(): bool
    {
        // An exclusion IS a statement about which products are discounted — "everything
        // except this one". Without it counting here, "10% off the food but not the treat"
        // would fall back to an order-wide discount and quietly discount the treat too.
        return $this->hasInclusions() || $this->hasExclusions();
    }
}
