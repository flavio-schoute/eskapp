<?php

namespace App\Filament\Resources\IntegrationErrors\Pages;

use App\Filament\Resources\IntegrationErrors\IntegrationErrorResource;
use App\Models\IntegrationError;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageIntegrationErrors extends ManageRecords
{
    protected static string $resource = IntegrationErrorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resolveAll')
                ->label(fn (): string => 'Mark all resolved ('.IntegrationError::unresolved()->count().')')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->visible(fn (): bool => IntegrationError::unresolved()->exists())
                ->requiresConfirmation()
                ->modalHeading('Mark all errors as resolved?')
                ->modalDescription(fn (): string => trans_choice('{1} The 1 unresolved error will be marked as resolved.|[2,*] All :count unresolved errors will be marked as resolved.', IntegrationError::unresolved()->count()).' They stay in the list under the "Resolved" filter.')
                ->modalSubmitActionLabel('Mark all resolved')
                ->action(function (): void {
                    $count = IntegrationError::unresolved()->update(['resolved_at' => now()]);

                    Notification::make()
                        ->success()
                        ->title(trans_choice('{1} 1 error marked as resolved|[2,*] :count errors marked as resolved', $count))
                        ->send();
                }),
        ];
    }
}
