<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quiz dog payloads (ARCHITECTURE.md §7) — replaces v1's `dog_quiz` metaobject.
 * The theme's quiz posts here (frozen `/api/dogs/quiz` contract); on link, a real
 * `dogs` row is created and attached to the customer/subscription.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_dogs', function (Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();                 // returned as `data.id` to the theme
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('linked_dog_id')->nullable()->constrained('dogs')->nullOnDelete();
            $table->json('payload');                               // raw quiz answers
            $table->json('variant_refs')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_dogs');
    }
};
