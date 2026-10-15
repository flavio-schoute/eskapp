<?php

namespace App\Filament\Widgets;

use App\Models\AffiliateInvoice;
use App\Support\RevenueReport;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * This month's invoiced and received commission and the affiliate manager's share, shown on the Revenue page.
 */
class RevenueStats extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $month = RevenueReport::forMonth(now(RevenueReport::Timezone));
        $settings = RevenueReport::commissionSettings();
        $open = AffiliateInvoice::query()->whereIn('status', ['issued', 'issuing', 'pending-payment', 'overdue'])->get(['amount']);
        $percentage = rtrim(rtrim(number_format($settings['percentage'], 2, ',', ''), '0'), ',');

        return [
            Stat::make("Invoiced in {$month['label']}", RevenueReport::money($month['invoiced']))
                ->description(trans_choice('{0} No invoices yet|{1} 1 invoice · :total incl. VAT|[2,*] :count invoices · :total incl. VAT', $month['invoiced_count'], ['total' => RevenueReport::money($month['invoiced_incl_vat'])]))
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('warning'),
            Stat::make("Received in {$month['label']}", RevenueReport::money($month['received']))
                ->description(trans_choice('{0} Nothing received yet|{1} 1 payment · :total incl. VAT|[2,*] :count payments · :total incl. VAT', $month['received_count'], ['total' => RevenueReport::money($month['received_incl_vat'])]))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success'),
            Stat::make('Still to be received', RevenueReport::money((float) $open->sum('amount')))
                ->description(trans_choice('{0} No open invoices|{1} 1 open invoice, all months|[2,*] :count open invoices, all months', $open->count()))
                ->icon(Heroicon::OutlinedClock)
                ->color('info'),
            Stat::make("Commission {$settings['manager']}", RevenueReport::money($month['commission']))
                ->description("{$percentage}% of received in {$month['label']}")
                ->icon(Heroicon::OutlinedUserCircle)
                ->color('primary'),
        ];
    }
}
