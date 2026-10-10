<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SubmissionStatus;
use App\Enums\TriageCategory;
use App\Models\Encounter;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormSubmission>
 */
class FormSubmissionFactory extends Factory
{
    protected $model = FormSubmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'form_template_id' => FormTemplate::factory(),
            'encounter_id' => Encounter::factory(),
            'authored_by' => User::factory(),
            'schema_version' => 1,
            'status' => SubmissionStatus::Submitted,
            'gcs_total' => 15,
            'triage_category' => TriageCategory::Urgent,
            'payload_data' => [
                'systolic' => 130,
                'diastolic' => 85,
            ],
        ];
    }

    public function draft(): static
    {
        return $this->state(fn (): array => ['status' => SubmissionStatus::Draft]);
    }
}
