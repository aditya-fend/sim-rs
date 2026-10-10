<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * erd.md §7 — an instantiated clinical form. `form_template_id` points at
     * the immutable schema version, never at "the current one".
     */
    public function up(): void
    {
        Schema::create('form_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('form_template_id');
            $table->uuid('encounter_id');
            $table->uuid('patient_id');
            $table->uuid('authored_by');
            $table->integer('schema_version');
            $table->jsonb('payload_data');
            // Narrative anamnesis is encrypted before it reaches SQL (FR-10).
            $table->text('encrypted_anamnesis')->nullable();
            $table->string('status', 32)->default('DRAFT');
            $table->smallInteger('gcs_total')->nullable();
            $table->string('triage_category', 32)->nullable();
            $table->string('primary_icd10_code', 16)->nullable();
            $table->string('objective_findings')->nullable();
            $table->char('signature_hash', 64)->nullable();
            $table->uuid('signed_by')->nullable();
            $table->timestampTz('signed_at')->nullable();
            $table->timestampsTz();

            $table->foreign('form_template_id')->references('id')->on('form_templates')->restrictOnDelete();
            $table->foreign('encounter_id')->references('id')->on('encounters')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('authored_by')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('signed_by')->references('id')->on('users')->nullOnDelete();

            $table->index('encounter_id', 'form_submissions_encounter_id_index');
            $table->index('patient_id', 'form_submissions_patient_id_index');
            $table->index('authored_by', 'form_submissions_authored_by_index');
            $table->index('signed_by', 'form_submissions_signed_by_index');
            $table->index('status', 'form_submissions_status_index');
            $table->index('triage_category', 'form_submissions_triage_category_index');
            $table->index('primary_icd10_code', 'form_submissions_primary_icd10_code_index');
            $table->index('created_at', 'form_submissions_created_at_index');
        });

        DB::statement("ALTER TABLE form_submissions ADD CONSTRAINT chk_form_submissions_status CHECK (status IN ('DRAFT', 'SUBMITTED', 'LOCKED'))");
        DB::statement('ALTER TABLE form_submissions ADD CONSTRAINT chk_form_submissions_gcs CHECK (gcs_total IS NULL OR (gcs_total >= 3 AND gcs_total <= 15))');
        DB::statement(<<<'SQL'
            ALTER TABLE form_submissions ADD CONSTRAINT chk_form_submissions_lock_integrity
            CHECK (
                (status = 'LOCKED' AND signature_hash IS NOT NULL AND signed_by IS NOT NULL AND signed_at IS NOT NULL)
                OR (status != 'LOCKED')
            )
        SQL);
        DB::statement('CREATE INDEX form_submissions_payload_data_gin ON form_submissions USING GIN (payload_data)');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
    }
};
