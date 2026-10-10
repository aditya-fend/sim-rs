<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedGcm;
use App\Enums\Sex;
use App\Models\Concerns\HasUuidKey;
use App\Services\Security\BlindIndexer;
use Carbon\CarbonInterface;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Patient registry (erd.md §4). `nik`, `phone` and `address` are encrypted by
 * the EncryptedGcm cast before they reach SQL; `nik_hash` is the deterministic
 * HMAC-SHA256 blind index that keeps NIK searchable (FR-08, FR-09).
 *
 * @property string $id
 * @property string $medical_record_number
 * @property string $full_name
 * @property CarbonInterface $birth_date
 * @property Sex $gender
 * @property string|null $ihs_patient_id
 * @property string|null $bpjs_card_number
 * @property string|null $nik_hash
 * @property string|null $nik_encrypted
 * @property string|null $phone_encrypted
 * @property string|null $address_encrypted
 *
 * @use HasFactory<PatientFactory>
 */
class Patient extends Model
{
    use HasFactory;
    use HasUuidKey;
    use SoftDeletes;

    protected $fillable = [
        'medical_record_number',
        'full_name',
        'birth_date',
        'gender',
        'ihs_patient_id',
        'bpjs_card_number',
    ];

    /**
     * These four carry the sensitive payload. `*_encrypted` hold ciphertext;
     * `nik_hash` is set from the model event below, never from user input, so
     * a mismatched pair cannot be smuggled in through mass assignment.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'gender' => Sex::class,
            'nik_encrypted' => EncryptedGcm::class,
            'phone_encrypted' => EncryptedGcm::class,
            'address_encrypted' => EncryptedGcm::class,
        ];
    }

    /**
     * Plain-language accessors over the ciphertext columns. Reads and writes both
     * route through Eloquent so the EncryptedGcm cast always runs — assigning to
     * `$this->attributes` directly would persist plaintext.
     */
    public function nik(): ?string
    {
        return $this->nik_encrypted;
    }

    public function phone(): ?string
    {
        return $this->phone_encrypted;
    }

    public function address(): ?string
    {
        return $this->address_encrypted;
    }

    /** Masked NIK for UI rendering: 331501******0001. */
    public function maskedNik(): string
    {
        return app(BlindIndexer::class)->mask($this->nik() ?? '');
    }

    /**
     * Writing the NIK also refreshes the blind index, so the two can never drift
     * apart (AC-05.2). Not mass-assignable: a payload cannot set `nik` without
     * `nik_hash` being recomputed for it.
     */
    public function setNik(?string $nik): static
    {
        $this->nik_encrypted = $nik;
        $this->nik_hash = ($nik === null || $nik === '')
            ? null
            : app(BlindIndexer::class)->hash($nik);

        return $this;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone_encrypted = $phone;

        return $this;
    }

    public function setAddress(?string $address): static
    {
        $this->address_encrypted = $address;

        return $this;
    }

    /**
     * Age in completed years, used by dosage rules and the patient strip.
     */
    public function ageInYears(?CarbonInterface $asOf = null): int
    {
        return (int) $this->birth_date->diffInYears($asOf ?? now());
    }

    public function isPediatric(): bool
    {
        return $this->ageInYears() < 18;
    }

    /** @return HasMany<Encounter, $this> */
    public function encounters(): HasMany
    {
        return $this->hasMany(Encounter::class);
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }

    /** @return HasMany<BpjsSepRecord, $this> */
    public function sepRecords(): HasMany
    {
        return $this->hasMany(BpjsSepRecord::class);
    }

    /**
     * @param  Builder<Patient>  $query
     * @return Builder<Patient>
     */
    public function scopeByMedicalRecordNumber(Builder $query, string $mrn): Builder
    {
        return $query->where('medical_record_number', $mrn);
    }
}
