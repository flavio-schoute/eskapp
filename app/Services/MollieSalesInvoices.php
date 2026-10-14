<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Models\Affiliate;
use App\Models\AffiliateInvoice;
use App\Models\User;
use App\Support\InvoiceVat;
use Illuminate\Support\Carbon;
use Mollie\Api\Http\Data\DataCollection;
use Mollie\Api\Http\Data\EmailDetails;
use Mollie\Api\Http\Data\InvoiceLine;
use Mollie\Api\Http\Data\Money;
use Mollie\Api\Http\Data\Recipient;
use Mollie\Api\Http\Requests\CreateSalesInvoiceRequest;
use Mollie\Api\Http\Requests\GetSalesInvoiceRequest;
use Mollie\Api\Types\RecipientType;
use Mollie\Api\Types\SalesInvoiceStatus;
use Mollie\Api\Types\VatMode;
use Mollie\Api\Types\VatScheme;
use Mollie\Laravel\Facades\Mollie;
use RuntimeException;

/**
 * Creates affiliate commission invoices in Mollie Invoicing → Facturen and lets Mollie email them to the partner.
 */
class MollieSalesInvoices
{
    public function __construct(public MollieCustomers $customers) {}

    /**
     * Create, issue and email an invoice with one commission line, and keep a record of it.
     */
    public function issue(Affiliate $affiliate, Carbon $period, string $amount, string $description, string $paymentTerm, string $emailSubject, string $emailBody, ?User $creator = null): AffiliateInvoice
    {
        if (! $this->customers->isConfigured()) {
            throw new RuntimeException('Mollie is not configured.');
        }

        $vatRate = InvoiceVat::rateFor($affiliate);
        $unitPrice = number_format((float) $amount, 2, '.', '');

        $salesInvoiceRequest = new CreateSalesInvoiceRequest(
            currency: 'EUR',
            status: SalesInvoiceStatus::ISSUED,
            vatScheme: VatScheme::STANDARD,
            vatMode: VatMode::EXCLUSIVE,
            paymentTerm: $paymentTerm,
            recipientIdentifier: "affiliate-{$affiliate->getKey()}",
            recipient: $this->recipient($affiliate),
            lines: new DataCollection([new InvoiceLine($description, 1, $vatRate, new Money('EUR', $unitPrice))]),
            memo: InvoiceVat::invoiceNote($affiliate),
            emailDetails: new EmailDetails($emailSubject, $emailBody),
        );

        $invoice = Mollie::send($salesInvoiceRequest);

        return AffiliateInvoice::create([
            'affiliate_id' => $affiliate->getKey(),
            'created_by' => $creator?->getKey(),
            'mollie_sales_invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoiceNumber,
            'status' => $invoice->status,
            'due_at' => $invoice->dueAt ? Carbon::parse($invoice->dueAt) : now()->addDays((int) $paymentTerm),
            'status_checked_at' => now(),
            'period' => $period->toDateString(),
            'description' => $description,
            'amount' => $unitPrice,
            'vat_rate' => $vatRate,
            'total_amount' => $invoice->totalAmount->value ?? null,
            'sent_to' => $affiliate->invoice_email,
            'pdf_url' => $invoice->_links->pdfLink->href ?? null,
        ]);
    }

    /**
     * Fetch the invoice's current status from Mollie, and remember when it was first seen as paid.
     */
    public function refreshStatus(AffiliateInvoice $invoice): AffiliateInvoice
    {
        $salesInvoice = Mollie::send(new GetSalesInvoiceRequest($invoice->mollie_sales_invoice_id));

        $invoice->update([
            'status' => $salesInvoice->status,
            'invoice_number' => $salesInvoice->invoiceNumber ?? $invoice->invoice_number,
            'due_at' => $salesInvoice->dueAt ? Carbon::parse($salesInvoice->dueAt) : $invoice->due_at,
            'paid_at' => $salesInvoice->status === SalesInvoiceStatus::PAID ? ($invoice->paid_at ?? now()) : null,
            'status_checked_at' => now(),
        ]);

        return $invoice;
    }

    /**
     * The invoice line description, e.g. "Affiliate commissie – september 2026", in the partner's language.
     */
    public function defaultDescription(?Affiliate $affiliate, Carbon $period): string
    {
        return 'Affiliate commissie – '.$period->locale($this->language($affiliate)->value)->translatedFormat('F Y');
    }

    private function recipient(Affiliate $affiliate): Recipient
    {
        return new Recipient(
            type: RecipientType::BUSINESS,
            email: $affiliate->invoice_email,
            streetAndNumber: $affiliate->invoice_address_line_1,
            postalCode: $affiliate->invoice_postal_code,
            city: $affiliate->invoice_city,
            country: strtoupper($affiliate->invoice_country),
            locale: $this->language($affiliate)->mollieLocale($affiliate->invoice_country),
            organizationName: $affiliate->invoice_company_name,
            organizationNumber: $affiliate->invoice_kvk_number ?: null,
            vatNumber: $affiliate->invoice_vat_number ?: null,
            phone: $affiliate->invoice_phone ?: null,
            streetAdditional: $affiliate->invoice_address_line_2 ?: null,
            region: $affiliate->invoice_region ?: null,
        );
    }

    private function language(?Affiliate $affiliate): InvoiceLanguage
    {
        if (! $affiliate) {
            return InvoiceLanguage::Dutch;
        }

        return $affiliate->invoice_language ?? InvoiceLanguage::suggestedFor($affiliate->invoice_country);
    }
}
