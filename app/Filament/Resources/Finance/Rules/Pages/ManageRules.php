<?php

namespace App\Filament\Resources\Finance\Rules\Pages;

use App\Filament\Resources\Finance\Rules\RuleResource;
use App\Services\Finance\Bookkeeping;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageRules extends ManageRecords
{
    protected static string $resource = RuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rerun')
                ->label('Regels opnieuw toepassen')
                ->icon(Heroicon::OutlinedPlay)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Runs all active rules over the open and proposed lines. Lines someone confirmed or excluded are left alone.')
                ->action(function (Bookkeeping $bookkeeping): void {
                    $result = $bookkeeping->applyRulesToOpenLines();

                    Notification::make()
                        ->success()
                        ->title(trans_choice('{0} Geen transacties veranderd|{1} 1 transactie veranderd|[2,*] :count transacties veranderd', $result['changed']))
                        ->body("{$result['matched']} open transacties passen bij een regel.")
                        ->send();
                }),
            CreateAction::make()
                ->label('Nieuwe regel')
                ->modalHeading('Nieuwe regel')
                ->using(fn (array $data) => RuleResource::save($data))
                ->successNotificationTitle('Regel opgeslagen'),
        ];
    }
}
