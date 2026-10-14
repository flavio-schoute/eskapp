<?php

namespace Database\Factories;

use App\Enums\EmailTemplateType;
use App\Enums\InvoiceLanguage;
use App\Models\EmailTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailTemplate>
 */
class EmailTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => EmailTemplateType::MollieInvoice,
            'name' => fake()->words(2, true),
            'language' => InvoiceLanguage::English,
            'subject' => 'Invoice {month}',
            'body' => "Hello {contact_person},\n\nYour invoice for {month}.",
            'is_default' => false,
        ];
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }
}
