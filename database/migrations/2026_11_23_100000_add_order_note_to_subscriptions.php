<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A standing note for the subscription — "leave it with the neighbour", "no plastic" —
 * written by the customer or an admin, and put on EVERY Shopify order the subscription
 * produces (the upcoming draft and the paid order alike).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->text('order_note')->nullable()->after('recurring_addons');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('order_note');
        });
    }
};
