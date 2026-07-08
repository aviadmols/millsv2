<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Subscriptions (ARCHITECTURE.md §3, §7). `status` is guarded (state machine) and
 * mass-assignment-protected — only transitionTo() changes it. `next_charge_at`
 * (a datetime) replaces v1's `charge_cycle` string; the scheduler selects on
 * `(status, next_charge_at)`. `payment_state` is the iCount wall.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('status')->default('pending');          // guarded enum
            $table->string('payment_state')->default('needs_card_update'); // payme|needs_card_update
            $table->unsignedTinyInteger('frequency_months')->default(1);   // 1 | 2
            $table->timestamp('next_charge_at')->nullable();
            $table->string('original_order_id')->nullable();
            $table->string('draft_order_id')->nullable();          // "upcoming order" preview only
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->json('meta')->nullable();
            $table->string('legacy_shopify_gid')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'next_charge_at']);           // scheduler fan-out
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
