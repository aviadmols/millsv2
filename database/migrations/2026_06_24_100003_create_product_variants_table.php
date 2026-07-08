<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Variant cache (ARCHITECTURE.md §1c). Subscriptions/dogs reference variants by
 * their Shopify variant id. Mills fields (grams, pack_size, flavor_key) are parsed
 * from the SKU as in v1's ProductCatalogService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->string('shopify_variant_id')->unique();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('sku')->nullable()->index();
            $table->decimal('price', 12, 2)->nullable();
            $table->integer('position')->nullable();
            $table->string('image_url')->nullable();           // variant image ?? product image
            $table->integer('grams')->nullable();              // parsed from SKU
            $table->integer('pack_size')->nullable();          // parsed from SKU (15/30)
            $table->string('flavor_key')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
