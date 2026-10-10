<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Models\Concerns\HasUuidKey;
use Carbon\CarbonInterface;
use Database\Factories\EncounterFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single clinical service visit (erd.md §5). Unencrypted by design: it holds
 * no identity data, only references to the encrypted patient row.
 *
 * @property string $id
 * @property string $encounter_number
 * @property EncounterType $encounter_type
 * @property EncounterStatus $status
 * @property CarbonInterface $admission_time
 * @property CarbonInterface|null $discharge_time
 * @property string|null $satusehat_encounter_id
 *

 * @use HasFactory<EncounterFactory>
 */
class Encounter extends Model
{
    use HasFactory;
    use HasUuidKey;

    /** Architecture.md: outbound FHIR/BPJS calls are queued, never inline. */
    public const SATUSEHAT_PENDING = 'PENDING';

    public const SATUSEHAT_PENDING_MAPPING = 'SATUSEHAT_PENDING_MAPPING';

    public const SATUSEHAT_SYNCED = 'SYNCED';

    public const SATUSEHAT_FAILED = 'FAILED';

    protected $fillable = [
        'encounter_number',
        'patient_id',
        'dpjp_user_id',
        'encounter_type',
        'status',
        'service_unit',
        'admission_time',
        'discharge_time',
        'satusehat_encounter_id',
        'satusehat_sync_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'encounter_type' => EncounterType::class,
            'status' => EncounterStatus::class,
            'admission_time' => 'datetime',
            'discharge_time' => 'datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function dpjp(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dpjp_user_id');
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /** @return HasMany<DraftSubmission, $this> */
    public function drafts(): HasMany
    {
        return $this->hasMany(DraftSubmission::class);
    }

    /** @return HasMany<BpjsSepRecord, $this> */
    public function sepRecords(): HasMany
    {
        return $this->hasMany(BpjsSepRecord::class);
    }

    /**
     * @param  Builder<Encounter>  $query
     * @return Builder<Encounter>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [
            EncounterStatus::Arrived->value,
            EncounterStatus::InProgress->value,
        ]);
    }
}
