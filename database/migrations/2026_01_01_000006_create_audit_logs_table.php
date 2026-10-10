<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * erd.md §9 — append-only Permenkes No. 24/2022 access trail (ADR-3).
     *
     * Note: erd.md §"Data Integrity Rules" originally specified
     *     RULE ... ON UPDATE/DELETE DO INSTEAD NOTHING
     * which silently swallows the statement. The trigger installed in
     * 2026_01_01_000011_add_clinical_integrity_triggers.php raises instead,
     * matching AC-08.2 ("prevents SQL updates and hard deletions") and
     * IT-05 ("intercepts query and blocks execution").
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('user_id')->nullable();
            // Snapshot of the active role: a later role change must not rewrite history.
            $table->string('role_name', 64);
            $table->string('action', 32);
            $table->string('resource_type', 128);
            $table->uuid('resource_id');
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->char('payload_checksum', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();

            $table->index('user_id', 'audit_logs_user_id_index');
            $table->index(['resource_type', 'resource_id'], 'audit_logs_resource_type_resource_id_index');
            $table->index('action', 'audit_logs_action_index');
            $table->index('created_at', 'audit_logs_created_at_index');
        });

        DB::statement("ALTER TABLE audit_logs ADD CONSTRAINT chk_audit_logs_action CHECK (action IN ('VIEW', 'CREATE', 'UPDATE', 'DELETE', 'LOCK', 'EXPORT', 'LOGIN', 'LOGOUT'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
