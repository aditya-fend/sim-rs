<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Database-level enforcement for the two immutability rules in erd.md
     * §"Data Integrity Rules" (ADR-3). Application policies alone are not
     * sufficient: a direct SQL session or a future code path could bypass them.
     */
    public function up(): void
    {
        // Rule 2 — audit_logs is append-only. Both mutation verbs raise.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_reject_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    'audit_logs is append-only (Permenkes No. 24/2022): % on audit_logs is not permitted',
                    TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER audit_logs_no_update
                BEFORE UPDATE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_mutation();

            CREATE TRIGGER audit_logs_no_delete
                BEFORE DELETE ON audit_logs
                FOR EACH ROW EXECUTE FUNCTION audit_logs_reject_mutation();
        SQL);

        // Rule 1 — once LOCKED, the clinical content of a submission is frozen.
        // Status transitions LOCKED -> LOCKED are allowed so the row stays
        // internally consistent with chk_form_submissions_lock_integrity.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION form_submissions_guard_locked() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'LOCKED'
                   AND (NEW.payload_data        IS DISTINCT FROM OLD.payload_data
                     OR NEW.encrypted_anamnesis IS DISTINCT FROM OLD.encrypted_anamnesis
                     OR NEW.primary_icd10_code  IS DISTINCT FROM OLD.primary_icd10_code
                     OR NEW.objective_findings  IS DISTINCT FROM OLD.objective_findings
                     OR NEW.status              <> 'LOCKED') THEN
                    RAISE EXCEPTION
                        'form_submission % is LOCKED: clinical content is immutable (Permenkes No. 24/2022)', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER form_submissions_lock_guard
                BEFORE UPDATE ON form_submissions
                FOR EACH ROW EXECUTE FUNCTION form_submissions_guard_locked();

            CREATE OR REPLACE FUNCTION form_submissions_guard_locked_delete() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'LOCKED' THEN
                    RAISE EXCEPTION
                        'form_submission % is LOCKED and cannot be deleted (Permenkes No. 24/2022)', OLD.id
                        USING ERRCODE = 'restrict_violation';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER form_submissions_no_delete
                BEFORE DELETE ON form_submissions
                FOR EACH ROW EXECUTE FUNCTION form_submissions_guard_locked_delete();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS form_submissions_no_delete ON form_submissions;
            DROP TRIGGER IF EXISTS form_submissions_lock_guard ON form_submissions;
            DROP FUNCTION IF EXISTS form_submissions_guard_locked_delete();
            DROP FUNCTION IF EXISTS form_submissions_guard_locked();
            DROP TRIGGER IF EXISTS audit_logs_no_delete ON audit_logs;
            DROP TRIGGER IF EXISTS audit_logs_no_update ON audit_logs;
            DROP FUNCTION IF EXISTS audit_logs_reject_mutation();
        SQL);
    }
};
