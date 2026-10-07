<?php

namespace Database\Factories;

use App\Models\SlackMember;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SlackMember>
 */
class SlackMemberFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'slack_user_id' => 'U'.fake()->unique()->regexify('[A-Z0-9]{10}'),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
