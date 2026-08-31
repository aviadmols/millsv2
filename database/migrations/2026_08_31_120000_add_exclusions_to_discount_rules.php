<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Everything except this one."
 *
 * The rules could only ever say what a discount APPLIES to, so "10% off the food, but not
 * the treat" had to be written as a list of every product that should be discounted — a
 * list that silently goes stale the day a new flavour is added, quietly giving away a
 * discount nobody granted, or withholding one they did.
 *
 * An exclusion is the honest shape for that intent: it survives the catalogue changing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discount_rules', function (Blueprint $table) {
            $table->json('excluded_product_ids')->nullable()->after('pack_sizes');
            $table->json('excluded_variant_ids')->nullable()->after('excluded_product_ids');
        });
    }

    public function down(): void
    {
        Schema::table('discount_rules', function (Blueprint $table) {
            $table->dropColumn(['excluded_product_ids', 'excluded_variant_ids']);
        });
    }
};
