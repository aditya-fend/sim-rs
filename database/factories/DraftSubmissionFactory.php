<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DraftSubmission;
use App\Models\Encounter;
use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DraftSubmission>
 */
class DraftSubmissionFactory extends Factory
{
    protected $model = DraftSubmission::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory(),
            'form_template_id' => FormTemplate::factory(),
            'user_id' => User::factory(),
            'draft_payload' => ['systolic' => 120],
            'expires_at' => now()->addHours(72),
        ];
    }
}
