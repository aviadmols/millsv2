<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved payment methods (ARCHITECTURE.md §7, D6). One PayMe buyer_key per customer
 * charges ALL their subscriptions — this replaces v1's fragile BillingLog-mining
 * and makes the "one card for the whole customer" rule structural. `buyer_key` is
 * encrypted at the model layer; never logged, never rendered.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('gateway')->default('payme');
            $table->text('buyer_key');                             // encrypted cast on the model
            $table->string('masked_card')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('source')->nullable();                  // card_update|order|import
            $table->timestamp('captured_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};
