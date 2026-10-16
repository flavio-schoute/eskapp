<?php

namespace App\Filament\Resources\DebtorCases\Pages;

use App\Filament\Pages\Debtors;
use App\Filament\Resources\DebtorCases\DebtorCaseResource;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewDebtorCase extends ViewRecord
{
    protected static string $resource = DebtorCaseResource::class;

    public function getBreadcrumbs(): array
    {
        return [
            Debtors::getUrl() => 'Debiteurenbeheer',
            'Dossier',
        ];
    }

    protected function getHeaderActions(): array
    {
        $actions = DebtorCaseResource::caseActions();

        return [
            $actions[0]->button()->color('success'),
            ActionGroup::make(array_slice($actions, 1))
                ->label('More')
                ->icon(Heroicon::OutlinedEllipsisVertical)
                ->button()
                ->color('gray'),
        ];
    }
}
