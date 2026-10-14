<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum MessageTemplateType: string implements HasDescription, HasLabel
{
    case MollieInvoice = 'mollie_invoice';
    case SlackInvoice = 'slack_invoice';

    public function getLabel(): string
    {
        return "{$this->channel()} · ".match ($this) {
            self::MollieInvoice => 'Mollie invoice',
            self::SlackInvoice => 'Invoice message',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::MollieInvoice => 'The email Mollie sends with an affiliate commission invoice.',
            self::SlackInvoice => 'The message posted in the affiliate\'s Slack channel after an invoice was sent, with the invoice PDF attached.',
        };
    }

    /**
     * Where the message is sent: by email or in Slack.
     */
    public function channel(): string
    {
        return match ($this) {
            self::MollieInvoice => 'Email',
            self::SlackInvoice => 'Slack',
        };
    }

    /**
     * The template types sent through a channel ("Email" or "Slack").
     *
     * @return list<self>
     */
    public static function forChannel(string $channel): array
    {
        return array_values(array_filter(self::cases(), fn (self $type): bool => $type->channel() === $channel));
    }

    /**
     * Whether messages of this type have a subject (emails do, Slack messages do not).
     */
    public function hasSubject(): bool
    {
        return $this === self::MollieInvoice;
    }

    /**
     * The placeholders that can be used in this type of template, with what they are replaced by.
     *
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        $common = [
            '{contact_person}' => 'Contact person (or the company name when there is none)',
            '{company_name}' => 'Company name on the invoice',
            '{partner_name}' => 'Partner name in the app',
            '{month}' => 'Invoice period in the template\'s language, e.g. "september 2026"',
        ];

        return match ($this) {
            self::MollieInvoice => $common,
            self::SlackInvoice => [
                '{mention}' => 'Tags the partner\'s Slack contact (their name when no Slack member ID is set)',
                ...$common,
                '{contact_person}' => 'Slack contact person (or the invoice contact person)',
                '{invoice_number}' => 'Invoice number, e.g. "INV-0042"',
                '{amount}' => 'Invoice total incl. VAT, e.g. "€ 121,00"',
                '{email}' => 'Email address the invoice was sent to',
            ],
        };
    }
}
