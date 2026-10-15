<?php

namespace Database\Factories;

use App\Enums\DebtorCaseType;
use App\Models\DebtorCase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DebtorCase>
 */
class DebtorCaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $orderId = fake()->unique()->numberBetween(10000000, 19999999);

        return [
            'plug_and_pay_order_id' => $orderId,
            'type' => DebtorCaseType::Unpaid,
            'invoice_number' => 'ESKOOL'.fake()->unique()->numerify('2026#####'),
            'invoice_date' => now()->subDays(10),
            'product' => 'Van consument naar ondernemer traject',
            'amount' => '1000.00',
            'amount_with_tax' => '1210.00',
            'customer_name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => '0612345678',
            'country' => 'NL',
            'payment_url' => "https://eskool.plugandpay.nl/orders/payment-link/{$orderId}",
            'synced_at' => now(),
        ];
    }

    public function chargedBack(): static
    {
        return $this->state(fn (): array => ['type' => DebtorCaseType::ChargedBack]);
    }
}
