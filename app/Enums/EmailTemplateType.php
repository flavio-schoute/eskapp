<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum EmailTemplateType: string implements HasDescription, HasLabel
{
    case MollieInvoice = 'mollie_invoice';

    public function getLabel(): string
    {
        return match ($this) {
            self::MollieInvoice => 'Mollie invoice',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::MollieInvoice => 'The email Mollie sends with an affiliate commission invoice.',
        };
    }

    /**
     * The placeholders that can be used in this type of template, with what they are replaced by.
     *
     * @return array<string, string>
     */
    public function placeholders(): array
    {
        return match ($this) {
            self::MollieInvoice => [
                '{contact_person}' => 'Contact person (or the company name when there is none)',
                '{company_name}' => 'Company name on the invoice',
                '{partner_name}' => 'Partner name in the app',
                '{month}' => 'Invoice period, e.g. "september 2026", in the template\'s language',
            ],
        };
    }
}
