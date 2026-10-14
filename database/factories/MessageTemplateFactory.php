<?php

namespace Database\Factories;

use App\Enums\InvoiceLanguage;
use App\Enums\MessageTemplateType;
use App\Models\MessageTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MessageTemplate>
 */
class MessageTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => MessageTemplateType::MollieInvoice,
            'name' => fake()->words(2, true),
            'language' => InvoiceLanguage::English,
            'subject' => 'Invoice {month}',
            'body' => "Hello {contact_person},\n\nYour invoice for {month}.",
            'is_default' => false,
        ];
    }

    public function slack(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => MessageTemplateType::SlackInvoice,
            'subject' => null,
            'body' => 'Hey {mention}, invoice {invoice_number} for {month}: {amount}, sent to {email}.',
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_default' => true,
        ]);
    }
}
