<?php

namespace App\Observers;

use App\Models\ActivityLog;
use App\Models\AffiliateInvoice;

class AffiliateInvoiceObserver
{
    public function created(AffiliateInvoice $invoice): void
    {
        ActivityLog::record(
            $invoice->affiliate,
            'invoice_sent',
            'Sent invoice '.($invoice->invoice_number ?? '').' for '.$invoice->period->translatedFormat('F Y').' (€ '.number_format((float) ($invoice->total_amount ?? $invoice->amount), 2, ',', '.').") to {$invoice->sent_to}",
        );
    }

    public function updated(AffiliateInvoice $invoice): void
    {
        if ($invoice->wasChanged('status') && in_array($invoice->status, ['paid', 'cancelled', 'expired'], true)) {
            ActivityLog::record($invoice->affiliate, 'invoice_status', "Invoice {$invoice->invoice_number} is {$invoice->status}");
        }
    }
}
