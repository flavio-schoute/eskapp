<?php

namespace App\Console\Commands;

use App\Enums\Integration;
use App\Models\AffiliateInvoice;
use App\Services\IntegrationErrorLogger;
use App\Services\MollieSalesInvoices;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('invoices:sync-payment-status')]
#[Description('Check Mollie for the status of every affiliate invoice that can still change')]
class SyncInvoicePaymentStatuses extends Command
{
    public function handle(MollieSalesInvoices $invoices, IntegrationErrorLogger $errorLogger): int
    {
        $openInvoices = AffiliateInvoice::query()->with('affiliate')->notFinal()->get();
        $paid = 0;

        foreach ($openInvoices as $invoice) {
            try {
                if ($invoices->refreshStatus($invoice)->isPaid()) {
                    $paid++;
                }
            } catch (Throwable $exception) {
                $errorLogger->record(Integration::Mollie, 'Check payment status', $exception, $invoice->affiliate);
            }
        }

        $this->info("Checked {$openInvoices->count()} open invoices, {$paid} now paid.");

        return self::SUCCESS;
    }
}
