<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Not in erd.md. Added for api-spec.md §"Webhooks": hospital enterprise
     * systems subscribe to clinical lifecycle events with a shared secret used
     * to sign the outbound body. Recorded in erd.md §11.
     */
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('label', 100);
            $table->string('url', 500);
            // Stored hashed; the plaintext is shown once at registration.
            $table->string('secret_hash', 64);
            $table->jsonb('events');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('is_active', 'webhook_endpoints_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
    }
};
