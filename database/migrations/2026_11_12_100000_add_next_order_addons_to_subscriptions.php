<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One-time extras for the NEXT order only — toys, snacks, tasting boxes.
 *
 * Food lives on the dog and repeats every cycle; extras were stored the same way
 * (dogs.addons_products), so a tasting box "added once" shipped and billed forever. These
 * are [{variant_id, quantity}], go out with the next charge, and are cleared after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('next_order_addons')->nullable()->after('line_items_overridden_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('next_order_addons');
        });
    }
};
