<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EncounterStatus;
use App\Enums\EncounterType;
use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Encounter>
 */
class EncounterFactory extends Factory
{
    protected $model = Encounter::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_number' => 'ENC-'.now()->format('Ym').'-'.fake()->unique()->numerify('####'),
            'patient_id' => Patient::factory(),
            'dpjp_user_id' => null,
            'encounter_type' => EncounterType::Emergency,
            'status' => EncounterStatus::Arrived,
            'service_unit' => 'IGD',
            'admission_time' => now(),
        ];
    }

    public function inpatient(): static
    {
        return $this->state(fn (): array => [
            'encounter_type' => EncounterType::Inpatient,
            'service_unit' => 'Bangsal Dahlia',
        ]);
    }

    public function assignedTo(User $dpjp): static
    {
        return $this->state(fn (): array => ['dpjp_user_id' => $dpjp->id]);
    }
}
