<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money that went back.
 *
 * The ledger could say a row was `refunded` but not how much or when — enough for a full
 * refund, useless for a partial one, and a partial refund that leaves the row `succeeded`
 * with no trace is money that left the business without the books noticing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->decimal('refunded_amount', 12, 2)->nullable()->after('amount');
            $table->timestamp('refunded_at')->nullable()->after('executed_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->dropColumn(['refunded_amount', 'refunded_at']);
        });
    }
};
