<?php

namespace Database\Factories;

use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AffiliateInvoice>
 */
class AffiliateInvoiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'affiliate_id' => Affiliate::factory()->withInvoiceDetails(),
            'mollie_sales_invoice_id' => 'invoice_'.fake()->unique()->bothify('??????????'),
            'invoice_number' => 'INV-'.fake()->unique()->numerify('####'),
            'status' => 'issued',
            'period' => now()->subMonthNoOverflow()->startOfMonth(),
            'description' => 'Affiliate commissie',
            'amount' => '100.00',
            'vat_rate' => '21.00',
            'total_amount' => '121.00',
            'sent_to' => fake()->companyEmail(),
            'pdf_url' => null,
        ];
    }
}
