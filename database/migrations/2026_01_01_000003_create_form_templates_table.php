<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * erd.md §6 — versioned declarative form definitions (ADR-1). Rows are
     * append-only: publishing a revision inserts a new row and retires the
     * previous one, so historical submissions keep resolving against the exact
     * schema they were captured under (AC-01.3, R-02).
     */
    public function up(): void
    {
        Schema::create('form_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64);
            $table->string('name');
            $table->integer('version');
            $table->jsonb('schema_definition');
            $table->boolean('is_active')->default(false);
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['code', 'version'], 'form_templates_code_version_unique');
            $table->index(['code', 'is_active'], 'form_templates_code_is_active_index');
        });

        // Exactly one active version per template family (erd.md §"Single Active Version Constraint").
        DB::statement(
            'CREATE UNIQUE INDEX form_templates_single_active_version_idx
             ON form_templates (code) WHERE is_active = TRUE'
        );
        DB::statement('ALTER TABLE form_templates ADD CONSTRAINT chk_form_templates_version CHECK (version >= 1)');
        DB::statement('CREATE INDEX form_templates_schema_definition_gin ON form_templates USING GIN (schema_definition)');
    }

    public function down(): void
    {
        Schema::dropIfExists('form_templates');
    }
};
