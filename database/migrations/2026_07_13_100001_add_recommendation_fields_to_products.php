<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two catalog facts the food recommender cannot work without.
 *
 * - products.multiplier — the food's energy density in kcal per gram, held in
 *   Shopify as the `product.multiplier` metafield. The whole recommendation
 *   pivots on it: gramsBenchmark = ceil(calories / multiplier). Defaults to 1
 *   (the same fallback the storefront uses when the metafield is unset).
 *
 * - product_variants.available — the recommender prefers a pack at or above the
 *   dog's requirement, but only if it can actually be bought; without stock
 *   state it would happily recommend an unbuyable variant.
 *
 * dogs.neutered is v1's `sirus`: an un-neutered dog burns ~11% more, and v1's
 * own fallback for "unknown" is "not neutered", so nullable is the honest type.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('multiplier', 8, 3)->default(1)->after('tags');

            // The eligibility rules match a "class" against the product TYPE or any
            // tag (theme: hasTClass), and restrict to the dog collection
            // (matchesPetCollection). Both facts have to live locally or the filters
            // silently pass everything.
            $table->string('product_type')->nullable()->after('multiplier');
            $table->json('collections')->nullable()->after('product_type');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->boolean('available')->default(true)->after('price');
        });

        Schema::table('dogs', function (Blueprint $table) {
            $table->boolean('neutered')->nullable()->after('sex');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['multiplier', 'product_type', 'collections']));
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('available'));
        Schema::table('dogs', fn (Blueprint $table) => $table->dropColumn('neutered'));
    }
};
