<?php

namespace App\Console\Commands;

use App\Filament\Pages\AffiliateInvoices;
use App\Services\Slack;
use App\Support\InvoiceReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invoices:monthly-reminder {--force : Post the reminder now, whatever the day}')]
#[Description('Remind the team in Slack to send the affiliate invoices for last month, on the day set under Slack settings')]
class SendMonthlyInvoiceReminder extends Command
{
    public function handle(Slack $slack): int
    {
        $settings = InvoiceReminder::settings();

        if (! $slack->isConfigured() || blank($settings['channel'])) {
            $this->info('No Slack channel is configured for the invoice reminder.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! InvoiceReminder::isDue()) {
            return self::SUCCESS;
        }

        $slack->postMessage($settings['channel'], InvoiceReminder::render(
            $settings['message'],
            now()->subMonthNoOverflow(),
            InvoiceReminder::affiliateCount(),
            AffiliateInvoices::getUrl(panel: 'admin'),
        ));

        if (! $this->option('force')) {
            InvoiceReminder::markSent();
        }

        $this->info('Invoice reminder posted.');

        return self::SUCCESS;
    }
}
