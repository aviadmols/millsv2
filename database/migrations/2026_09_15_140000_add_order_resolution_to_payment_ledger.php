<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "I sorted this one out" on a paid charge with no order.
 *
 * The home-screen alert lists every customer who paid and got no Shopify order. Once an
 * admin has dealt with one — created the order by hand, refunded, shipped it some other
 * way — the row must be able to leave the alert, or the alert fills with handled cases and
 * the next real one is lost among them. Who closed it, when and how is kept on the charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->timestamp('order_resolved_at')->nullable()->after('order_attempted_at');
            $table->string('order_resolved_by')->nullable()->after('order_resolved_at');
            $table->text('order_resolved_note')->nullable()->after('order_resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->dropColumn(['order_resolved_at', 'order_resolved_by', 'order_resolved_note']);
        });
    }
};
