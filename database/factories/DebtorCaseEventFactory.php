<?php

namespace Database\Factories;

use App\Models\DebtorCase;
use App\Models\DebtorCaseEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtorCaseEvent>
 */
class DebtorCaseEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'debtor_case_id' => DebtorCase::factory(),
            'user_name' => fake()->name(),
            'type' => DebtorCaseEvent::Note,
            'title' => 'Note',
            'body' => '<p>Gebeld, betaalt vrijdag.</p>',
            'created_at' => now(),
        ];
    }
}
