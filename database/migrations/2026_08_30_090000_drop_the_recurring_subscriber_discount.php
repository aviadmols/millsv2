<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stop discounting the recurring cycle.
 *
 * Every subscription carried 10% (the column default, and what v1's note said), so each
 * upcoming order was built as list price less 10% — ₪171.00 of product billed as ₪153.90.
 * Aviad's decision on 2026-08-30: the recurring order is billed at the store's price, with
 * no second discount stacked on top of it.
 *
 * The mechanism is kept, not deleted: the column stays, Settings still exposes the rate,
 * and DraftOrderService still honours a non-zero value. What changes is that nobody gets
 * one unless it is granted deliberately. Existing rows are zeroed here — leaving them at
 * 10 would mean the change quietly applied to new subscribers only, which is the opposite
 * of what was asked.
 *
 * NOTE: this does not by itself change what anyone is charged. `next_charge_amount` is the
 * stored total of the upcoming Shopify draft, so each subscription bills the old amount
 * until its draft is rebuilt — `php artisan mills:refresh-upcoming-orders` does that, and
 * the normal post-charge refresh does it a cycle later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->change();
        });

        DB::table('subscriptions')->where('discount_percent', '>', 0)->update(['discount_percent' => 0]);
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(10)->change();
        });

        // The old per-subscription rates are not recoverable — 10 is what every row held.
        DB::table('subscriptions')->update(['discount_percent' => 10]);
    }
};
