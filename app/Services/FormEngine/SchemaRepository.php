<?php

declare(strict_types=1);

namespace App\Services\FormEngine;

use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Version resolution and publication (FR-02, ADR-1).
 *
 * Rows in `form_templates` are immutable. "Editing" a template means inserting
 * the next version and retiring the previous one inside one transaction, which
 * is what lets a two-year-old submission keep rendering against the schema it
 * was captured under (AC-01.3, R-02).
 */
final class SchemaRepository
{
    public function __construct(
        private readonly SchemaValidator $schemaValidator = new SchemaValidator,
    ) {}

    /**
     * The version a new intake form should render.
     */
    public function active(string $code): ?FormTemplate
    {
        return FormTemplate::query()
            ->where('code', $code)
            ->where('is_active', true)
            ->first();
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, FormTemplate>
     */
    public function activeByCode(array $codes): array
    {
        return FormTemplate::query()
            ->where('is_active', true)
            ->whereIn('code', $codes)
            ->get()
            ->keyBy('code')
            ->all();
    }

    /**
     * The exact version a historical submission was captured under.
     */
    public function versionFor(FormSubmission $submission): FormTemplate
    {
        return FormTemplate::query()
            ->where('code', $submission->template->code ?? '')
            ->where('version', $submission->schema_version)
            ->firstOrFail();
    }

    /**
     * Validate then insert as the next version (FR-02).
     *
     * @param  array<string, mixed>  $definition
     *
     * @throws ValidationException when the definition is structurally invalid.
     */
    public function publish(
        string $code,
        string $name,
        array $definition,
        User $author,
        bool $activate = true,
    ): FormTemplate {
        $errors = $this->schemaValidator->validate($definition);

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'schema_definition' => $errors,
            ]);
        }

        return DB::transaction(function () use ($code, $name, $definition, $author, $activate): FormTemplate {
            // Postgres rejects FOR UPDATE combined with an aggregate, so the
            // locked rows are fetched and reduced in PHP.
            $latest = FormTemplate::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->pluck('version')
                ->max();

            $nextVersion = ((int) $latest) + 1;

            if ($activate) {
                // The partial unique index form_templates_single_active_version_idx
                // allows only one active row per code, so the old one must be
                // retired before the new one is inserted.
                FormTemplate::query()->where('code', $code)->where('is_active', true)->update([
                    'is_active' => false,
                ]);
            }

            return FormTemplate::create([
                'code' => $code,
                'name' => $name,
                'version' => $nextVersion,
                'schema_definition' => $definition,
                'is_active' => $activate,
                'created_by' => $author->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<string>
     */
    public function check(array $definition): array
    {
        return $this->schemaValidator->validate($definition);
    }

    /**
     * Every version of a template family, newest first, for the admin history view.
     *
     * @return Collection<int, FormTemplate>
     */
    public function history(string $code)
    {
        return FormTemplate::query()
            ->where('code', $code)
            ->orderByDesc('version')
            ->get();
    }
}
