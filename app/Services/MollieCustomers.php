<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Models\Affiliate;
use Mollie\Api\Http\Requests\CreateCustomerRequest;
use Mollie\Api\Http\Requests\UpdateCustomerRequest;
use Mollie\Laravel\Facades\Mollie;

class MollieCustomers
{
    /**
     * Mollie rejects customer metadata larger than this many bytes of JSON.
     */
    private const MaxMetadataBytes = 1024;

    /**
     * Determine whether a real Mollie API key or access token is configured (not the package placeholder).
     */
    public function isConfigured(): bool
    {
        return (bool) preg_match('/^(test|live|access)_(?!x+$)\w{20,}$/', (string) config('mollie.key'));
    }

    /**
     * Create a Mollie customer for the affiliate and return its ID.
     */
    public function create(Affiliate $affiliate): string
    {
        return Mollie::send(new CreateCustomerRequest(...$this->payload($affiliate)))->id;
    }

    /**
     * Update the affiliate's existing Mollie customer.
     */
    public function update(string $customerId, Affiliate $affiliate): void
    {
        Mollie::send(new UpdateCustomerRequest($customerId, ...$this->payload($affiliate)));
    }

    /**
     * Build the customer fields. Mollie customers only store a name, email and locale, so the other
     * invoice details are kept in the metadata.
     *
     * @return array{name: string, email: string, locale: string, metadata: array<string, mixed>}
     */
    public function payload(Affiliate $affiliate): array
    {
        $language = $affiliate->invoice_language ?? InvoiceLanguage::suggestedFor($affiliate->invoice_country);

        return [
            'name' => $affiliate->invoice_company_name,
            'email' => $affiliate->invoice_email,
            'locale' => $language->mollieLocale($affiliate->invoice_country),
            'metadata' => $this->metadata($affiliate),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(Affiliate $affiliate): array
    {
        $metadata = array_filter([
            'affiliate_id' => $affiliate->getKey(),
            'type' => 'company',
            'organization_number' => $affiliate->invoice_kvk_number,
            'vat_number' => $affiliate->invoice_vat_number,
            'phone' => $affiliate->invoice_phone,
            'street_and_number' => $affiliate->invoice_address_line_1,
            'postal_code' => $affiliate->invoice_postal_code,
            'city' => $affiliate->invoice_city,
            'country' => $affiliate->invoice_country,
            'street_additional' => $affiliate->invoice_address_line_2,
            'region' => $affiliate->invoice_region,
            'contact_person' => $affiliate->invoice_contact_person,
        ], filled(...));

        // Drop the least important details first when the metadata would be too large for Mollie.
        foreach (['contact_person', 'region', 'street_additional', 'phone'] as $optionalKey) {
            if (strlen((string) json_encode($metadata)) <= self::MaxMetadataBytes) {
                break;
            }

            unset($metadata[$optionalKey]);
        }

        return $metadata;
    }
}
