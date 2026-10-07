<?php

namespace App\Filament\Resources\Affiliates\Widgets;

use App\Enums\AffiliateStatus;
use App\Models\Affiliate;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

class AffiliateStatusOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    #[On('affiliate-status-updated')]
    public function refreshCounts(): void {}

    protected function getStats(): array
    {
        $countsByStatus = Affiliate::query()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        return collect(AffiliateStatus::cases())
            ->map(fn (AffiliateStatus $status): Stat => Stat::make($status->getLabel(), $countsByStatus->get($status->value, 0))
                ->icon($status->getIcon())
                ->color($status->getColor()))
            ->all();
    }
}
