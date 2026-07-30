<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Cardcom hand-off record.
 *
 * A customer who saves a card here starts being charged by US — but their old recurring
 * charge still lives in Cardcom, and removing it is a MANUAL act in Cardcom's own admin.
 * Until someone does it, the customer is being billed twice. These columns are the record
 * that a named admin actually did it — the dashboard queue lists every migrated customer
 * where they are still null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('cardcom_removed_at')->nullable();
            $table->foreignId('cardcom_removed_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cardcom_removed_by');
            $table->dropColumn('cardcom_removed_at');
        });
    }
};
