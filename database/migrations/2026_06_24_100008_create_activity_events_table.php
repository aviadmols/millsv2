<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timeline / audit feed (ARCHITECTURE.md §7). Append-only — `created_at` only, no
 * updates or deletes. Every status transition, charge, card update, and admin /
 * customer action records a row here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->string('actor')->default('system');            // system|customer|webhook|admin:{id}
            $table->string('kind')->index();
            $table->json('details')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index(['subscription_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_events');
    }
};
