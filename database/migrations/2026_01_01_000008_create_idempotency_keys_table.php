<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Not in erd.md. Added because api-spec.md §"Idempotency" requires a
     * repeated X-Idempotency-Key within 24 hours to replay the cached 201
     * instead of writing the record twice. Recorded in erd.md §11.
     */
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable();
            $table->string('key', 100);
            $table->string('endpoint', 191);
            // The exact 201 body that was returned the first time.
            $table->jsonb('response_body');
            $table->unsignedSmallInteger('response_status');
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->unique(['user_id', 'key', 'endpoint'], 'idempotency_keys_user_key_endpoint_unique');
            $table->index('expires_at', 'idempotency_keys_expires_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
