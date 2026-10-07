<?php

namespace Database\Factories;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Models\Affiliate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Affiliate>
 */
class AffiliateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => fake()->randomElement(AffiliateType::cases()),
            'status' => AffiliateStatus::Active,
            'login_url' => fake()->url(),
            'username' => fake()->userName(),
            'password' => fake()->password(),
            'agreement' => '20% recurring per sale. Monthly automatic payout.',
            'payment_method' => fake()->randomElement(AffiliatePaymentMethod::cases()),
            'affiliate_link' => fake()->url(),
            'notes' => null,
        ];
    }

    public function pipeline(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AffiliateStatus::Pipeline,
        ]);
    }

    public function onHold(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AffiliateStatus::OnHold,
        ]);
    }

    public function stopped(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AffiliateStatus::Stopped,
        ]);
    }
}
