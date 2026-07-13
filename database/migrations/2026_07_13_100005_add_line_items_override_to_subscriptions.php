<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A hand-edited upcoming order.
 *
 * The order a subscription places is DERIVED from its dogs' chosen products. So an admin
 * who edits only the Shopify draft changes nothing that matters: the draft is a projection,
 * and the charge would still bill the original lines — the customer would be charged for one
 * thing and shipped another.
 *
 * The override is therefore stored HERE, where both the draft builder and the order builder
 * read from, so the preview, the charge and the shipment cannot disagree.
 *
 * It is a ONE-OFF, cleared after the cycle it was made for is charged — the same semantics
 * v1 gave add-ons. A permanent change belongs on the dog, not on a single order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->json('line_items_override')->nullable()->after('discount_percent');
            $table->timestamp('line_items_overridden_at')->nullable()->after('line_items_override');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn(['line_items_override', 'line_items_overridden_at']);
        });
    }
};
