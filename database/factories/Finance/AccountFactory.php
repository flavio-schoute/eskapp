<?php

namespace Database\Factories\Finance;

use App\Enums\Finance\AccountType;
use App\Models\Finance\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'ING zakelijk',
            'iban' => 'NL00INGB0000000001',
            'type' => AccountType::Bank,
        ];
    }
}
