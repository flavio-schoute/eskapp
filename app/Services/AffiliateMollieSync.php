<?php

namespace App\Services;

use App\Models\Affiliate;
use RuntimeException;

class AffiliateMollieSync
{
    /**
     * The affiliate attributes that are sent to Mollie; a change to one of these updates the Mollie customer.
     *
     * @var list<string>
     */
    public const SyncedAttributes = [
        'invoice_company_name',
        'invoice_kvk_number',
        'invoice_vat_number',
        'invoice_email',
        'invoice_phone',
        'invoice_address_line_1',
        'invoice_address_line_2',
        'invoice_postal_code',
        'invoice_city',
        'invoice_region',
        'invoice_country',
        'invoice_language',
        'invoice_contact_person',
    ];

    public function __construct(public MollieCustomers $customers) {}

    /**
     * Whether the affiliate should have a Mollie customer: the sync is switched on, the affiliate is invoiced
     * and its invoice details are complete.
     */
    public function shouldSync(Affiliate $affiliate): bool
    {
        return config('services.mollie.sync_customers')
            && (bool) $affiliate->payment_method?->requiresInvoiceDetails()
            && $affiliate->hasCompleteInvoiceDetails();
    }

    /**
     * Make sure an invoiced affiliate has a Mollie customer.
     */
    public function ensureCustomer(Affiliate $affiliate): ?string
    {
        if (filled($affiliate->mollie_customer_id) || ! $this->shouldSync($affiliate)) {
            return $affiliate->mollie_customer_id;
        }

        $this->guardConfigured();

        $customerId = $this->customers->create($affiliate);

        $affiliate->forceFill(['mollie_customer_id' => $customerId])->saveQuietly();

        return $customerId;
    }

    /**
     * Send the affiliate's current invoice details to its Mollie customer.
     */
    public function updateCustomer(Affiliate $affiliate): void
    {
        if (blank($affiliate->mollie_customer_id) || ! $this->shouldSync($affiliate)) {
            return;
        }

        $this->guardConfigured();

        $this->customers->update($affiliate->mollie_customer_id, $affiliate);
    }

    private function guardConfigured(): void
    {
        if (! $this->customers->isConfigured()) {
            throw new RuntimeException('Mollie is not configured.');
        }
    }
}
