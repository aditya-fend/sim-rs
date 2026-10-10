<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * erd.md §10 — BPJS VClaim SEP bridge registry (D8).
     *
     * SEP number format. Three documents disagree and no two agree:
     *   AC-07.2 template  0301R001{MM}{YY}B{7 digits}  -> 20 characters,
     *                      yet the prose calls it "19-character".
     *   erd.md regex      ^0301R[0-9]{3}[0-9]{4}B[0-9]{7}$ -> 19 characters,
     *                      and cannot match AC-07.2's own example.
     *   api-spec.md       0301R0010325B0000142 -> 20 characters.
     * The concrete example is the only fully specified artefact, so the
     * constraint below encodes its shape: an 8-character faskes code, a
     * 4-character MMYY stamp, the 'B' separator and a 7-digit counter.
     */
    public function up(): void
    {
        Schema::create('bpjs_sep_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('encounter_id');
            $table->uuid('patient_id');
            $table->string('sep_number', 32)->unique();
            $table->string('bpjs_card_number', 16);
            $table->string('poli_code', 16);
            $table->string('faskes_rujukan_code', 32)->nullable();
            $table->string('diagnosis_icd10', 16);
            $table->string('signature_used', 128);
            $table->bigInteger('request_timestamp');
            $table->jsonb('response_payload');
            $table->timestampsTz();

            $table->foreign('encounter_id')->references('id')->on('encounters')->restrictOnDelete();
            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();

            $table->index('patient_id', 'bpjs_sep_records_patient_id_index');
            $table->index('encounter_id', 'bpjs_sep_records_encounter_id_index');
            $table->index('bpjs_card_number', 'bpjs_sep_records_bpjs_card_number_index');
        });

        DB::statement("ALTER TABLE bpjs_sep_records ADD CONSTRAINT chk_bpjs_sep_number_format CHECK (sep_number ~ '^[0-9]{4}R[0-9]{3}[0-9]{4}B[0-9]{7}$')");
        DB::statement('ALTER TABLE bpjs_sep_records ADD CONSTRAINT chk_bpjs_card_number_length CHECK (LENGTH(bpjs_card_number) = 13)');
    }

    public function down(): void
    {
        Schema::dropIfExists('bpjs_sep_records');
    }
};
