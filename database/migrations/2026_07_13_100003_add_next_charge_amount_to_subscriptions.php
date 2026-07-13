<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the next charge will actually cost.
 *
 * ChargeOrchestrator::resolveAmount() read `meta.price` — a key NOTHING in the system
 * has ever written. Both real subscriptions carry only {imported_from, v1_charge_cycle},
 * so every charge would have aborted with `no_amount` before touching PayMe. Recurring
 * billing could not have taken a single shekel.
 *
 * The amount is now a first-class column, kept in step with the upcoming order's total
 * (the draft order IS the next order, so its total IS the next charge). It is also what
 * the admin screen shows, so the number a human sees and the number PayMe is asked for
 * are the same number by construction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->decimal('next_charge_amount', 12, 2)->nullable()->after('next_charge_at');
            $table->timestamp('next_charge_amount_at')->nullable()->after('next_charge_amount');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['next_charge_amount', 'next_charge_amount_at']);
        });
    }
};
