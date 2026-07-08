<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment ledger — the immutable money truth (CLAUDE.md law #2, ARCHITECTURE.md
 * §3/§7). A `pending` row is written BEFORE every PayMe call; the unique
 * `idempotency_key` makes a re-charge impossible. `raw_response_masked` never
 * contains card data or a raw buyer_key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->string('context');                             // recurring|retry|manual
            $table->string('idempotency_key')->unique();           // deterministic; the wall
            $table->string('status')->default('pending');          // guarded LedgerStatus
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 8)->default('ILS');
            $table->string('payme_transaction_id')->nullable();
            $table->string('shopify_order_id')->nullable();
            $table->string('draft_order_id')->nullable();
            $table->string('failure_code')->nullable();
            $table->text('failure_message')->nullable();
            $table->json('raw_response_masked')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_ledger');
    }
};
