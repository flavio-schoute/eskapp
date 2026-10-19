<?php

namespace Database\Factories\Finance;

use App\Enums\Finance\CategoryKind;
use App\Models\Finance\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    protected $model = Category::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'kind' => CategoryKind::Expense,
            'default_vat_rate' => 0.21,
        ];
    }

    /**
     * A subcategory under the given group (created when none is given).
     */
    public function under(?Category $group = null): static
    {
        return $this->state(fn (): array => ['parent_id' => $group?->getKey() ?? Category::factory()]);
    }
}
