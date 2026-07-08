<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single Shopify app connection (ARCHITECTURE.md §1b, §7). One row: the OAuth
 * offline access token (encrypted at the model layer), the shop domain, granted
 * scopes, and install/uninstall timestamps. Single-store — no multi-tenant Shop
 * table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shopify_connection', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain')->nullable()->unique();
            $table->text('access_token')->nullable();             // encrypted cast
            $table->json('scopes')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shopify_connection');
    }
};
