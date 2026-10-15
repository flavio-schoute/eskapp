<?php

namespace App\Filament\Widgets;

use App\Enums\AffiliateStatus;
use App\Filament\Pages\AffiliateInvoices;
use App\Filament\Pages\Contracts;
use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Models\Affiliate;
use App\Models\AffiliateContract;
use App\Models\AffiliateInvoice;
use App\Models\User;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

/**
 * The dashboard tiles: open and overdue invoices (admins only), contracts waiting for a signature and the pipeline.
 */
class DashboardStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $open = AffiliateInvoice::query()->whereIn('status', ['issued', 'issuing', 'pending-payment', 'overdue']);
        $overdue = AffiliateInvoice::query()->overdue();
        $waitingContracts = AffiliateContract::query()
            ->open()
            ->whereIn('id', AffiliateContract::query()->selectRaw('max(id)')->groupBy('affiliate_id'));
        $oldestWaiting = $waitingContracts->clone()->min('created_at');
        $daysWaiting = $oldestWaiting ? (int) now()->diffInDays($oldestWaiting, true) : null;
        $pipeline = Affiliate::query()->where('status', AffiliateStatus::Pipeline)->count();

        $isAdmin = auth()->user() instanceof User && auth()->user()->isAdmin();

        return array_values(array_filter([
            ! $isAdmin ? null : Stat::make('Open invoices', static::money($this->totalOf($open)))
                ->description(trans_choice('{0} Nothing to be paid|{1} 1 invoice waiting for payment|[2,*] :count invoices waiting for payment', $open->count()))
                ->icon(Heroicon::OutlinedClock)
                ->color('info')
                ->url(AffiliateInvoices::getUrl()),
            ! $isAdmin ? null : Stat::make('Overdue invoices', $overdue->count())
                ->description(($count = $overdue->count()) > 0 ? static::money($this->totalOf($overdue)).' past the due date' : 'Everything paid on time')
                ->descriptionIcon($count > 0 ? Heroicon::OutlinedExclamationTriangle : Heroicon::OutlinedCheckCircle)
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color($count > 0 ? 'danger' : 'success')
                ->url(AffiliateInvoices::getUrl()),
            Stat::make('Contracts waiting for a signature', $waitingContracts->count())
                ->description(match (true) {
                    $daysWaiting === null => 'No contracts waiting',
                    $daysWaiting === 0 => 'Sent today',
                    default => 'Longest waiting: '.trans_choice('{1} 1 day|[2,*] :count days', $daysWaiting),
                })
                ->icon(Heroicon::OutlinedDocumentText)
                ->color($waitingContracts->count() > 0 ? 'warning' : 'gray')
                ->url(Contracts::getUrl()),
            Stat::make('Affiliates in pipeline', $pipeline)
                ->description('Not active yet')
                ->icon(AffiliateStatus::Pipeline->getIcon())
                ->color(AffiliateStatus::Pipeline->getColor())
                ->url(AffiliateResource::getUrl()),
        ]));
    }

    /**
     * @param  Builder<AffiliateInvoice>  $invoices
     */
    protected function totalOf(Builder $invoices): float
    {
        return (float) $invoices->clone()->get(['amount', 'total_amount'])->sum(fn (AffiliateInvoice $invoice): float => (float) ($invoice->total_amount ?? $invoice->amount));
    }

    public static function money(float $amount): string
    {
        return '€ '.number_format($amount, 2, ',', '.');
    }
}
