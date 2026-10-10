<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Sex;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'medical_record_number' => fake()->unique()->numerify('00-##-##'),
            'full_name' => fake()->name(),
            'birth_date' => fake()->dateTimeBetween('-70 years', '-1 year'),
            'gender' => fake()->randomElement(Sex::cases()),
            'ihs_patient_id' => 'P'.fake()->unique()->numerify('#########'),
            // chk_bpjs_card_number_length requires exactly 13 characters.
            'bpjs_card_number' => fake()->unique()->numerify('#############'),
        ];
    }

    public function male(): static
    {
        return $this->state(fn (): array => ['gender' => Sex::Male]);
    }

    public function female(): static
    {
        return $this->state(fn (): array => ['gender' => Sex::Female]);
    }

    /** Child patient, for the pediatric dosage rules in FR-07. */
    public function child(int $ageYears = 5): static
    {
        return $this->state(fn (): array => [
            'birth_date' => now()->subYears($ageYears),
        ]);
    }
}
