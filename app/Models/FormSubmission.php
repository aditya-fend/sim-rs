<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedGcm;
use App\Enums\SubmissionStatus;
use App\Enums\TriageCategory;
use App\Models\Concerns\HasUuidKey;
use App\Services\Security\SignOffService;
use Carbon\CarbonInterface;
use Database\Factories\FormSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An instantiated clinical form (erd.md §7).
 *
 * `form_template_id` points at the immutable schema version rather than at the
 * template family, so AC-01.3 ("an older submission created under schema v1.0
 * renders without layout discrepancies") falls out of the data model instead
 * of from a runtime lookup.
 *
 * @property string $id
 * @property string $form_template_id
 * @property string $encounter_id
 * @property string $patient_id
 * @property string $authored_by
 * @property int $schema_version
 * @property array<string, mixed> $payload_data
 * @property SubmissionStatus $status
 * @property int|null $gcs_total
 * @property TriageCategory|null $triage_category
 * @property string|null $primary_icd10_code
 * @property string|null $objective_findings
 * @property string|null $signature_hash
 * @property string|null $signed_by
 * @property CarbonInterface|null $signed_at
 * @property string|null $encrypted_anamnesis
 *

 * @use HasFactory<FormSubmissionFactory>
 */
class FormSubmission extends Model
{
    use HasFactory;
    use HasUuidKey;

    protected $fillable = [
        'form_template_id',
        'encounter_id',
        'patient_id',
        'authored_by',
        'schema_version',
        'payload_data',
        'status',
        'gcs_total',
        'triage_category',
        'primary_icd10_code',
        'objective_findings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_data' => 'array',
            'status' => SubmissionStatus::class,
            'gcs_total' => 'integer',
            'triage_category' => TriageCategory::class,
            'schema_version' => 'integer',
            'encrypted_anamnesis' => EncryptedGcm::class,
            'signed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FormTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authored_by');
    }

    /** @return BelongsTo<User, $this> */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /**
     * Narrative anamnesis, decrypted (FR-10). Null when the form carries none.
     */
    public function anamnesis(): ?string
    {
        return $this->encrypted_anamnesis;
    }

    public function setAnamnesis(?string $anamnesis): static
    {
        $this->encrypted_anamnesis = $anamnesis;

        return $this;
    }

    public function isLocked(): bool
    {
        return $this->status->isLocked();
    }

    /**
     * The exact byte sequence that gets hashed at sign-off
     * (dfd.md §P4.0): submission id + canonical payload + signer + timestamp.
     */
    public function signatureSubject(?CarbonInterface $signedAt, string $signerId): string
    {
        return app(SignOffService::class)->subject(
            $this->id,
            $this->payload_data,
            $signerId,
            $signedAt,
        );
    }
}
