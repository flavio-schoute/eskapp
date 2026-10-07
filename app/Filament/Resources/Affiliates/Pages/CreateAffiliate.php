<?php

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\Affiliates\Pages\Concerns\SyncsAffiliateIntegrations;
use App\Models\Affiliate;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateAffiliate extends CreateRecord
{
    use SyncsAffiliateIntegrations;

    protected static string $resource = AffiliateResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $this->pullAgreementUpload($data);
    }

    protected function afterCreate(): void
    {
        $this->syncIntegrations();
    }

    protected function getCreatedNotification(): ?Notification
    {
        /** @var Affiliate $affiliate */
        $affiliate = $this->getRecord();

        $ready = array_filter([
            $affiliate->slack_channel_name ? "Slack channel #{$affiliate->slack_channel_name}" : null,
            $affiliate->google_drive_folder_id ? 'Google Drive folder' : null,
        ]);

        return Notification::make()
            ->success()
            ->title("Affiliate {$affiliate->name} created")
            ->body($ready ? implode(' and ', $ready).' are ready.' : null);
    }
}
