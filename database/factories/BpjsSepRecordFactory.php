<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\BpjsSepRecord;
use App\Models\Encounter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BpjsSepRecord>
 */
class BpjsSepRecordFactory extends Factory
{
    protected $model = BpjsSepRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory(),
            // Matches chk_bpjs_sep_number_format: 8-char faskes code, MMYY, 'B', 7 digits.
            'sep_number' => '0301R001'.now()->format('my').'B'.fake()->unique()->numerify('#######'),
            'bpjs_card_number' => fake()->unique()->numerify('#############'),
            'poli_code' => 'IGD',
            'diagnosis_icd10' => 'K29.7',
            'signature_used' => base64_encode(hash_hmac('sha256', 'seed', 'key', true)),
            'request_timestamp' => time(),
            'response_payload' => ['metaData' => ['code' => '200', 'message' => 'Sukses']],
        ];
    }
}
