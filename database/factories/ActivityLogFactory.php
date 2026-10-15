<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Affiliate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $user = User::factory();

        return [
            'affiliate_id' => Affiliate::factory(),
            'affiliate_name' => fake()->company(),
            'user_id' => $user,
            'user_name' => fake()->name(),
            'event' => 'updated',
            'description' => 'Updated the affiliate',
            'changes' => ['Status' => ['old' => 'Active', 'new' => 'On hold']],
            'created_at' => now(),
        ];
    }
}
