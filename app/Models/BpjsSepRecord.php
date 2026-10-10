<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use Database\Factories\BpjsSepRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * BPJS VClaim transaction record (erd.md §10, D8). Retains the HMAC signature
 * and the raw gateway response so a claim can be replayed or audited later.
 *
 * @property string $id
 * @property string $sep_number
 * @property string $bpjs_card_number
 * @property string $poli_code
 * @property string $diagnosis_icd10
 * @property string $signature_used
 * @property int $request_timestamp
 * @property array<string, mixed> $response_payload
 *
 * @use HasFactory<BpjsSepRecordFactory>
 */
class BpjsSepRecord extends Model
{
    use HasFactory;
    use HasUuidKey;

    protected $fillable = [
        'encounter_id',
        'patient_id',
        'sep_number',
        'bpjs_card_number',
        'poli_code',
        'faskes_rujukan_code',
        'diagnosis_icd10',
        'signature_used',
        'request_timestamp',
        'response_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_timestamp' => 'integer',
            'response_payload' => 'array',
        ];
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
}
