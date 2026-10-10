<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormTemplate>
 */
class FormTemplateFactory extends Factory
{
    protected $model = FormTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'TEST-'.fake()->unique()->numerify('##'),
            'name' => 'Formulir Uji '.fake()->word(),
            'version' => 1,
            'is_active' => true,
            'created_by' => User::factory(),
            'schema_definition' => [
                'sections' => [
                    [
                        'key' => 'vitals',
                        'label' => 'Tanda Vital',
                        'fields' => [
                            [
                                'name' => 'systolic',
                                'label' => 'Tekanan Darah Sistole',
                                'type' => 'number',
                                'unit' => 'mmHg',
                                'min' => 40,
                                'max' => 300,
                                'validation_rules' => ['required', 'integer', 'min:40', 'max:300'],
                                'conditional_display' => null,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
