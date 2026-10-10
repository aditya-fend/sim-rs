<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * erd.md §8 — server-side twin of the browser localStorage draft buffer
     * (R-03: incognito-mode workstations evict localStorage).
     */
    public function up(): void
    {
        Schema::create('draft_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('encounter_id');
            $table->uuid('form_template_id');
            $table->uuid('user_id');
            $table->jsonb('draft_payload');
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->foreign('encounter_id')->references('id')->on('encounters')->cascadeOnDelete();
            $table->foreign('form_template_id')->references('id')->on('form_templates')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(
                ['encounter_id', 'form_template_id', 'user_id'],
                'draft_submissions_encounter_template_user_unique'
            );
            $table->index('expires_at', 'draft_submissions_expires_at_index');
        });

        DB::statement('CREATE INDEX draft_submissions_draft_payload_gin ON draft_submissions USING GIN (draft_payload)');
    }

    public function down(): void
    {
        Schema::dropIfExists('draft_submissions');
    }
};
