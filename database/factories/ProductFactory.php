<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plug_and_pay_product_id' => fake()->unique()->numberBetween(100000, 499999),
            'title' => fake()->words(3, true),
            'type' => 'one_off',
            'in_use' => false,
            'orders_count' => 0,
            'synced_at' => now(),
        ];
    }
}
