<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extras repeat every order after all (Aviad, 2026-11-12, the same day): every change a
 * customer makes to their order applies to all the orders that follow, food or extra
 * alike. The column keeps its data; only its name stops promising "next order only".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->renameColumn('next_order_addons', 'recurring_addons');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->renameColumn('recurring_addons', 'next_order_addons');
        });
    }
};
