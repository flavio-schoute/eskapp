<?php

namespace App\Support;

use App\Enums\InvoiceLanguage;
use App\Models\Affiliate;

/**
 * VAT for affiliate commission invoices, based on the partner's country:
 * Netherlands 21%, other EU countries with a VAT number 0% reverse charge, outside the EU 0%,
 * and 21% for EU partners without a VAT number.
 */
class InvoiceVat
{
    public const DutchRate = '21.00';

    public const ZeroRate = '0.00';

    /**
     * EU member states (ISO 3166-1 alpha-2), excluding the Netherlands.
     *
     * @var list<string>
     */
    private const OtherEuCountries = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GR', 'HR', 'HU',
        'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    public static function rateFor(Affiliate $affiliate): string
    {
        return match (true) {
            self::isReverseCharge($affiliate), self::isOutsideEu($affiliate) => self::ZeroRate,
            default => self::DutchRate,
        };
    }

    public static function isReverseCharge(Affiliate $affiliate): bool
    {
        return in_array($affiliate->invoice_country, self::OtherEuCountries, true) && filled($affiliate->invoice_vat_number);
    }

    public static function isOutsideEu(Affiliate $affiliate): bool
    {
        return filled($affiliate->invoice_country)
            && $affiliate->invoice_country !== 'NL'
            && ! in_array($affiliate->invoice_country, self::OtherEuCountries, true);
    }

    /**
     * A short explanation of the VAT for the generate invoice form.
     */
    public static function explanation(Affiliate $affiliate): string
    {
        return match (true) {
            self::isReverseCharge($affiliate) => '0% – VAT reverse charged (EU business with VAT number '.$affiliate->invoice_vat_number.')',
            self::isOutsideEu($affiliate) => '0% – outside the EU',
            $affiliate->invoice_country === 'NL' => '21% – Netherlands',
            default => '21% – EU business without a VAT number',
        };
    }

    /**
     * The note printed on the invoice for 0% VAT, in the partner's preferred language.
     */
    public static function invoiceNote(Affiliate $affiliate): ?string
    {
        $dutch = ($affiliate->invoice_language ?? InvoiceLanguage::suggestedFor($affiliate->invoice_country)) === InvoiceLanguage::Dutch;

        return match (true) {
            self::isReverseCharge($affiliate) => $dutch
                ? 'Btw verlegd naar de afnemer (artikel 196 Btw-richtlijn 2006/112/EG). Btw-nummer afnemer: '.$affiliate->invoice_vat_number
                : 'VAT reverse charged to the recipient (Article 196, VAT Directive 2006/112/EC). Recipient VAT number: '.$affiliate->invoice_vat_number,
            self::isOutsideEu($affiliate) => $dutch
                ? 'Btw niet van toepassing: dienst verricht aan een afnemer buiten de EU.'
                : 'VAT not applicable: service supplied to a recipient outside the EU.',
            default => null,
        };
    }
}
