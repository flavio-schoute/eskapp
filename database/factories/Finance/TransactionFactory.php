<?php

namespace Database\Factories\Finance;

use App\Enums\Finance\TransactionStatus;
use App\Models\Finance\Account;
use App\Models\Finance\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'dedupe_hash' => hash('sha256', fake()->unique()->uuid()),
            'booked_on' => '2026-10-01',
            'amount_cents' => -12100,
            'counterparty_name' => 'Google Ireland Limited',
            'counterparty_iban' => 'NL00CITI0000000002',
            'description' => 'Google Ads 12345',
            'status' => TransactionStatus::Uncategorized,
        ];
    }
}
