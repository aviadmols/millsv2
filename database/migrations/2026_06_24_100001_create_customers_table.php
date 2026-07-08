<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers — local source of truth (ARCHITECTURE.md §7). The address is owned
 * here and pushed back to the Shopify customer (D14); `address_pushed_at` tracks
 * that sync. Identity links to Shopify via `shopify_customer_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_customer_id')->nullable()->unique();
            $table->string('email')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('address1')->nullable();
            $table->string('address2')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('country')->nullable();
            $table->string('zip')->nullable();
            $table->string('locale', 8)->default('he');
            $table->timestamp('address_pushed_at')->nullable();
            $table->json('meta')->nullable();
            $table->string('legacy_shopify_gid')->nullable()->index();
            $table->timestamps();

            $table->index('phone');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
