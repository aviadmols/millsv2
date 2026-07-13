<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The subscriber discount.
 *
 * The real recurring orders are not billed at list price: #70261 shows ₪171.00 of product
 * less a ₪17.10 discount — exactly 10% — and v1's quiz posted `discount: 0.9` with every
 * dog. A draft built without it would undercharge nobody but OVERcharge everybody, since
 * it would ask for the full ₪171.00 the customer has never actually paid.
 *
 * It is per-subscription rather than a global constant because the historical orders do
 * not agree on one number (one carries a ₪76.10 discount), so a single hardcoded rate
 * would silently be wrong for somebody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(10)->after('next_charge_amount_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('discount_percent'));
    }
};
