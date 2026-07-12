<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The clear, simple system-wide operation log. One append-only row per meaningful
 * action (API/storefront request, billing step, Shopify call, webhook, OTP, admin
 * action). Retention is enforced by `logs:prune` (config mills.logging.retention_days,
 * default 60 days) scheduled daily. Sensitive keys are masked before storage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 16)->default('info');        // info | warning | error
            $table->string('category', 32)->default('system');   // api|storefront|billing|shopify|webhook|otp|cron|admin|system
            $table->string('message', 500);
            $table->json('context')->nullable();                 // masked structured detail
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('method', 10)->nullable();
            $table->string('path', 255)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');                         // retention pruning
            $table->index(['category', 'created_at']);
            $table->index(['level', 'created_at']);
            $table->index('subscription_id');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_logs');
    }
};
