<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who asked for this money to move, and under what session.
 *
 * `raw_response_masked` is the GATEWAY's answer — putting our own audit facts in there would
 * be a lie about what the column holds. A card-update row needs to survive the 15-minute
 * cache that currently carries the session: the cache is how the flow runs, the ledger is how
 * we answer "who touched this customer's card" six months later, and how the reconciler finds
 * a card that was captured at PayMe but never returned to us.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->json('meta')->nullable()->after('raw_response_masked');
        });
    }

    public function down(): void
    {
        Schema::table('payment_ledger', function (Blueprint $table) {
            $table->dropColumn('meta');
        });
    }
};
