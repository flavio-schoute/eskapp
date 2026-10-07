<?php

namespace Database\Factories;

use App\Enums\Integration;
use App\Models\IntegrationError;
use Illuminate\Database\Eloquent\Factories\Factory;
use RuntimeException;

/**
 * @extends Factory<IntegrationError>
 */
class IntegrationErrorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'affiliate_id' => null,
            'integration' => fake()->randomElement(Integration::cases()),
            'action' => 'Create folder',
            'message' => fake()->sentence(),
            'exception_class' => RuntimeException::class,
            'occurrences' => 1,
            'last_occurred_at' => now(),
            'resolved_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes): array => [
            'resolved_at' => now(),
        ]);
    }
}
