<?php

namespace Database\Factories;

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Enums\InvoiceLanguage;
use App\Models\Affiliate;
use App\Services\MollieInvoicingCustomersExport;
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
            'payment_method' => AffiliatePaymentMethod::Automatic,
            'affiliate_link' => fake()->url(),
            'notes' => null,
        ];
    }

    public function withInvoiceDetails(): static
    {
        return $this->state(fn (array $attributes): array => [
            'payment_method' => AffiliatePaymentMethod::Invoice,
            'invoice_company_name' => fake()->company(),
            'invoice_address_line_1' => fake()->streetAddress(),
            'invoice_postal_code' => fake()->postcode(),
            'invoice_city' => fake()->city(),
            'invoice_country' => 'NL',
            'invoice_contact_person' => fake()->name(),
            'invoice_email' => fake()->companyEmail(),
            'invoice_language' => InvoiceLanguage::Dutch,
        ]);
    }

    /**
     * Mark the affiliate as uploaded to Mollie Invoicing with its current invoice details.
     */
    public function exportedToMollie(): static
    {
        return $this->afterCreating(fn (Affiliate $affiliate) => app(MollieInvoicingCustomersExport::class)->markAsExported(collect([$affiliate])));
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
