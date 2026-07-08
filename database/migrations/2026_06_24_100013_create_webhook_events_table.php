<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbound Shopify webhook log (ARCHITECTURE.md §1b). Dedupe by `webhook_id`
 * (Shopify's `X-Shopify-Webhook-Id`); raw payload persisted; processed
 * asynchronously. HMAC is verified by middleware before a row is written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('webhook_id')->nullable()->unique();   // X-Shopify-Webhook-Id
            $table->string('topic')->index();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('status')->default('received');        // received|processed|failed
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
