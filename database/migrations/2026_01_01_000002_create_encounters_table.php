<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * erd.md §5 — one clinical service visit, tying a patient to an attending
     * DPJP and a care period. Unencrypted: operational, not identity, data.
     */
    public function up(): void
    {
        Schema::create('encounters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('encounter_number', 64)->unique();
            $table->uuid('patient_id');
            $table->uuid('dpjp_user_id')->nullable();
            $table->string('encounter_type', 32);
            $table->string('status', 32)->default('arrived');
            $table->string('service_unit', 64);
            $table->timestampTz('admission_time');
            $table->timestampTz('discharge_time')->nullable();
            // Populated once the encounter has been pushed to SatuSehat.
            $table->string('satusehat_encounter_id')->nullable();
            $table->string('satusehat_sync_status', 32)->default('PENDING');
            $table->timestampsTz();

            $table->foreign('patient_id')->references('id')->on('patients')->restrictOnDelete();
            $table->foreign('dpjp_user_id')->references('id')->on('users')->nullOnDelete();

            $table->index('patient_id', 'encounters_patient_id_index');
            $table->index('dpjp_user_id', 'encounters_dpjp_user_id_index');
            $table->index('status', 'encounters_status_index');
            $table->index('admission_time', 'encounters_admission_time_index');
            $table->index('satusehat_encounter_id', 'encounters_satusehat_encounter_id_index');
        });

        DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_type CHECK (encounter_type IN ('EMER', 'IMP', 'AMB'))");
        DB::statement("ALTER TABLE encounters ADD CONSTRAINT chk_encounters_status CHECK (status IN ('arrived', 'in-progress', 'finished', 'cancelled'))");
        DB::statement('ALTER TABLE encounters ADD CONSTRAINT chk_encounters_dates CHECK (discharge_time IS NULL OR discharge_time >= admission_time)');
    }

    public function down(): void
    {
        Schema::dropIfExists('encounters');
    }
};
