<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discounts on the recurring cycle, decided by what is actually in the order.
 *
 * Until now there was one number per subscription, stamped at signup and never revisited,
 * so "10% on two-month plans" or "5% off the premium range" could only be done by editing
 * every subscription by hand — which means it was never done.
 *
 * A rule is a set of CONDITIONS and a percentage. Conditions are ANDed across the groups
 * that are filled in and ORed within a group: "frequency is 2 months AND the order contains
 * any of these tags". An empty group is not a condition at all, so a rule with nothing set
 * matches every subscription — deliberately, since that is how a plain store-wide discount
 * is expressed.
 *
 * `scope` decides what the percentage is taken off: the whole order, or only the lines that
 * matched the product conditions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->decimal('percent', 5, 2);

            // 'order' = a percentage off the whole order (what Shopify does most simply).
            // 'matching_lines' = only off the lines that matched the product conditions.
            $table->string('scope')->default('order');

            /*
             * Conditions. NULL means "not a condition" — never "match nothing", which is the
             * distinction that decides whether an unfilled field silently disables a rule.
             */
            $table->unsignedTinyInteger('frequency_months')->nullable();
            $table->json('product_ids')->nullable();
            $table->json('variant_ids')->nullable();
            $table->json('tags')->nullable();
            $table->json('pack_sizes')->nullable();

            // Only a tie-break: the highest-VALUE rule wins, and this settles a dead heat
            // so the outcome is never down to insertion order.
            $table->integer('priority')->default(0);

            $table->timestamps();

            $table->index(['is_active', 'priority']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_rules');
    }
};
