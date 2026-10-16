<?php

namespace Database\Factories;

use App\Enums\CustomerMatch;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'phone' => '+31612345678',
            'street' => 'Korvet',
            'house_number' => '2',
            'postal_code' => '3232TK',
            'city' => 'Brielle',
            'country' => 'NL',
            'orders_count' => 2,
            'total_amount' => '2176.16',
            'open_amount' => '0.00',
            'mollie_customer_ids' => ['cst_'.fake()->bothify('??????????')],
            'match_status' => CustomerMatch::Linked,
            'synced_at' => now(),
        ];
    }
}
