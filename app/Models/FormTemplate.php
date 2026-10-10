<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TriageCategory;
use App\Models\Concerns\HasUuidKey;
use Database\Factories\FormTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A versioned, append-only dynamic clinical form definition (erd.md §6, ADR-1).
 *
 * Rows are never edited. Publishing a change inserts version N+1 and flips the
 * previous row's `is_active` to false, so a submission can always resolve the
 * exact schema it was captured under (AC-01.3, R-02).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property int $version
 * @property array<string, mixed> $schema_definition
 * @property bool $is_active
 *

 * @use HasFactory<FormTemplateFactory>
 */
class FormTemplate extends Model
{
    use HasFactory;
    use HasUuidKey;

    protected $fillable = [
        'code',
        'name',
        'version',
        'schema_definition',
        'is_active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'schema_definition' => 'array',
            'is_active' => 'boolean',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /**
     * The version currently rendered for new intake.
     *
     * @param  Builder<FormTemplate>  $query
     * @return Builder<FormTemplate>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** @return HasMany<FormTemplate, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(self::class, 'code', 'code');
    }

    /** @return array<int, array<string, mixed>> */
    public function fields(): array
    {
        $sections = $this->schema_definition['sections'] ?? [];

        $fields = [];
        foreach ($sections as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /**
     * Field definitions keyed by field name, for the dynamic validator.
     *
     * @return array<string, array<string, mixed>>
     */
    public function fieldsByName(): array
    {
        $byName = [];
        foreach ($this->fields() as $field) {
            /** @var string $name */
            $name = $field['name'];
            $byName[$name] = $field;
        }

        return $byName;
    }

    /**
     * Fields flagged `confidential` in the schema. FR-10 requires these to be
     * encrypted before they are written into `payload_data`.
     *
     * @return array<int, string>
     */
    public function confidentialFieldNames(): array
    {
        $names = [];
        foreach ($this->fields() as $field) {
            if (($field['confidential'] ?? false) === true) {
                /** @var string $name */
                $name = $field['name'];
                $names[] = $name;
            }
        }

        return $names;
    }

    /** @return array<string, string> */
    public function triageOptions(): array
    {
        return TriageCategory::options();
    }
}
