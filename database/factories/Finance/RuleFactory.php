<?php

namespace Database\Factories\Finance;

use App\Models\Finance\Rule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Rule>
 */
class RuleFactory extends Factory
{
    protected $model = Rule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'priority' => 100,
            'active' => true,
            'conditions' => [['field' => 'counterparty', 'op' => 'contains', 'value' => 'google']],
            'action' => ['categoryId' => null],
            'source' => 'manual',
        ];
    }
}
