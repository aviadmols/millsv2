<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dogs (ARCHITECTURE.md §7). Includes the two fields v1's mirror dropped —
 * `selected_variants` (flavors) and `addons_products` — which MUST be imported.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->nullOnDelete();
            $table->string('name')->nullable();
            $table->tinyInteger('sex')->nullable();                // 0 male / 1 female
            $table->decimal('age', 5, 1)->nullable();
            $table->decimal('weight', 6, 1)->nullable();
            $table->text('allergies')->nullable();
            $table->tinyInteger('activity')->nullable();           // 0/1/2
            $table->tinyInteger('body')->nullable();               // 0/1/2
            $table->integer('calories_per_day')->nullable();
            $table->date('birth_date')->nullable();
            $table->boolean('double_food')->default(false);
            $table->string('avatar')->nullable();
            $table->string('status')->default('active');
            $table->string('subscription_status')->nullable();
            $table->json('selected_variants')->nullable();         // flavor variant ids
            $table->json('addons_products')->nullable();           // add-on variant ids
            $table->string('legacy_shopify_gid')->nullable()->index();
            $table->timestamps();

            $table->index('subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dogs');
    }
};
