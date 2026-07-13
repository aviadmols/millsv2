<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SMS login has to find the customer by the number they typed. Stored numbers and
 * typed numbers are almost never byte-identical (050-123-4567 vs +972501234567), so
 * we keep a normalised, indexed key alongside the display value and match on that.
 *
 * Backfills every existing customer, so the very first SMS login works rather than
 * waiting for each row to be touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('phone_normalized', 16)->nullable()->after('phone')->index();
        });

        DB::table('customers')
            ->whereNotNull('phone')
            ->orderBy('id')
            ->chunkById(500, function ($customers) {
                foreach ($customers as $customer) {
                    $key = PhoneNumber::normalise($customer->phone);
                    if ($key === null) {
                        continue;
                    }

                    DB::table('customers')->where('id', $customer->id)->update(['phone_normalized' => $key]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('phone_normalized'));
    }
};
