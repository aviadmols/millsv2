<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local product cache (ARCHITECTURE.md §1c). Shopify is authoritative; this is a
 * read cache so hot paths never call Shopify live. `image_url` is the Shopify CDN
 * URL (no re-hosting). Refreshed by products/* webhooks + nightly + manual button.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_product_id')->unique();   // numeric id or gid suffix
            $table->string('title');
            $table->string('handle')->nullable();
            $table->string('status')->default('active');       // active|draft|unlisted
            $table->string('image_url')->nullable();           // featuredImage.url (CDN)
            $table->json('tags')->nullable();
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
