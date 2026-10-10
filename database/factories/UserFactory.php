<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'sip_number' => null,
            'ihs_practitioner_id' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function dpjp(): static
    {
        return $this->state(fn (): array => [
            'sip_number' => fake()->numerify('###/###/IP.D/####'),
            'ihs_practitioner_id' => 'DR'.fake()->unique()->numerify('#########'),
        ]);
    }
}
