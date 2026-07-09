<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit log of every scheduled (CRON) task that runs — the visible proof the
 * scheduler is working (the v1 blind spot). Populated by the ScheduledTask
 * event listeners in AppServiceProvider; surfaced in the admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cron_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command')->index();
            $table->string('status')->default('completed'); // completed | failed | skipped
            $table->unsignedInteger('runtime_ms')->nullable();
            $table->text('output')->nullable();
            $table->timestamp('ran_at')->useCurrent();

            $table->index(['command', 'ran_at']);
            $table->index('ran_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_runs');
    }
};
