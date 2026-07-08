<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mail settings (ARCHITECTURE.md §7, D12) — a single row, editable from the admin
 * panel. Holds per-template subject/body overrides (strtr-rendered) AND the SMTP
 * configuration (encrypted password on the model). NULL template fields fall back
 * to the platform defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_settings', function (Blueprint $table) {
            $table->id();

            // SMTP (admin-managed; password encrypted at the model layer)
            $table->boolean('use_custom_smtp')->default(false);
            $table->string('smtp_host')->nullable();
            $table->unsignedInteger('smtp_port')->nullable();
            $table->string('smtp_encryption')->nullable();        // tls|ssl|null
            $table->string('smtp_username')->nullable();
            $table->text('smtp_password')->nullable();            // encrypted cast
            $table->string('from_name')->nullable();
            $table->string('from_address')->nullable();

            // Per-template overrides (NULL => platform default)
            $table->json('templates')->nullable();                // { key: {subject, body} }

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_settings');
    }
};
