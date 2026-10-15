<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Models\Affiliate;
use App\Models\AffiliateContract;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AffiliateContract>
 */
class AffiliateContractFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $contractId = fake()->unique()->uuid();

        return [
            'affiliate_id' => Affiliate::factory(),
            'esignatures_contract_id' => $contractId,
            'status' => ContractStatus::Sent,
            'signer_name' => fake()->name(),
            'signer_email' => fake()->safeEmail(),
            'sign_page_url' => "https://esignatures.com/sign/{$contractId}",
            'is_test' => true,
        ];
    }

    public function signed(): static
    {
        return $this->state(fn (): array => [
            'status' => ContractStatus::Signed,
            'signed_at' => now(),
        ]);
    }
}
