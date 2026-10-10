<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * erd.md §4 — patient registry. NIK, phone and address are stored as
     * AES-256-GCM ciphertext; `nik_hash` is the deterministic HMAC-SHA256
     * blind index that keeps them searchable without decrypting the column
     * (FR-09, NFR-04).
     */
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('medical_record_number', 32)->unique();
            $table->text('nik_encrypted');
            $table->char('nik_hash', 64)->unique();
            $table->string('full_name');
            $table->date('birth_date');
            $table->string('gender', 10);
            $table->text('phone_encrypted')->nullable();
            $table->text('address_encrypted')->nullable();
            $table->string('ihs_patient_id')->nullable()->unique();
            $table->string('bpjs_card_number', 16)->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index('full_name', 'patients_full_name_index');
            $table->index('birth_date', 'patients_birth_date_index');
            $table->index('bpjs_card_number', 'patients_bpjs_card_number_index');
        });

        DB::statement("ALTER TABLE patients ADD CONSTRAINT chk_patients_gender CHECK (gender IN ('male', 'female'))");
        DB::statement('ALTER TABLE patients ADD CONSTRAINT chk_bpjs_card_number_length CHECK (LENGTH(bpjs_card_number) = 13)');
    }

    public function down(): void
    {
        Schema::dropIfExists('patients');
    }
};
