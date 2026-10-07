<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Models\Affiliate;
use Illuminate\Support\Collection;

/**
 * Builds the CSV for Mollie Invoicing → Klanten → Uploaden, following Mollie's customer import template.
 */
class MollieInvoicingCustomersExport
{
    /**
     * The columns of Mollie's customer import template, in its order.
     *
     * @var list<string>
     */
    public const Columns = [
        'type',
        'company_name',
        'company_number',
        'first_name',
        'last_name',
        'email',
        'phone_number',
        'postal_code',
        'street_name_and_number',
        'address_line_2',
        'city',
        'country_code',
        'preferred_language',
    ];

    /**
     * @param  Collection<int, Affiliate>  $affiliates
     */
    public function toCsv(Collection $affiliates): string
    {
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, self::Columns, escape: '');

        foreach ($affiliates as $affiliate) {
            fputcsv($handle, $this->row($affiliate), escape: '');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Remember that these affiliates were exported, with a fingerprint of the exported details to spot later changes.
     *
     * @param  Collection<int, Affiliate>  $affiliates
     */
    public function markAsExported(Collection $affiliates): void
    {
        foreach ($affiliates as $affiliate) {
            $affiliate->forceFill([
                'mollie_exported_at' => now(),
                'mollie_export_fingerprint' => $this->fingerprint($affiliate),
            ])->saveQuietly();
        }
    }

    /**
     * A hash of the affiliate's exported row; it changes when any exported detail changes.
     */
    public function fingerprint(Affiliate $affiliate): string
    {
        return hash('sha256', json_encode($this->row($affiliate)));
    }

    /**
     * One affiliate as a template row. Mollie's template has no region column, so the region is added to address line 2.
     *
     * @return list<string>
     */
    public function row(Affiliate $affiliate): array
    {
        $language = $affiliate->invoice_language ?? InvoiceLanguage::suggestedFor($affiliate->invoice_country);
        $addressLine2 = collect([$affiliate->invoice_address_line_2, $affiliate->invoice_region])->filter()->implode(', ');

        return [
            'company',
            (string) $affiliate->invoice_company_name,
            (string) $affiliate->invoice_kvk_number,
            '',
            '',
            (string) $affiliate->invoice_email,
            (string) $affiliate->invoice_phone,
            (string) $affiliate->invoice_postal_code,
            (string) $affiliate->invoice_address_line_1,
            $addressLine2,
            (string) $affiliate->invoice_city,
            strtolower((string) $affiliate->invoice_country),
            $language->value,
        ];
    }
}
