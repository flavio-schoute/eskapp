<?php

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\Affiliates\Pages\Concerns\SyncsAffiliateIntegrations;
use App\Models\Affiliate;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAffiliate extends EditRecord
{
    use SyncsAffiliateIntegrations;

    protected static string $resource = AffiliateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->successNotificationTitle(fn (Affiliate $record): string => "Affiliate {$record->name} deleted"),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return $this->pullAgreementUpload($data);
    }

    protected function afterSave(): void
    {
        $this->syncIntegrations();

        $this->data['agreement_upload'] = null;
        $this->refreshFormData(['agreement_file_name', 'agreement_drive_url', 'google_drive_folder_id', 'slack_channel_name']);
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return "Affiliate {$this->getRecord()->name} saved";
    }
}
