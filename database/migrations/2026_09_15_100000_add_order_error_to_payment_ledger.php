<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a paid charge has no order.
 *
 * Order creation is deliberately compensating: the money has already moved, so a failure to
 * create the Shopify order never unwinds the charge. But the reason was written only to the
 * system log, while the billing history showed a silent "—" — so subscription 321 was
 * charged ₪414, got no order and therefore no shipment, and nothing on any screen said so
 * until somebody noticed four days later.
 *
 * The reason now lives on the charge itself, where the billing history and the home screen
 * can both read it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->text('order_error')->nullable()->after('shopify_order_id');
            $table->timestamp('order_attempted_at')->nullable()->after('order_error');
        });
    }

    public function down(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->dropColumn(['order_error', 'order_attempted_at']);
        });
    }
};
