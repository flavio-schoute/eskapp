<?php

namespace App\Filament\Resources\Affiliates\Pages;

use App\Filament\Resources\Affiliates\AffiliateResource;
use App\Filament\Resources\Affiliates\Pages\Concerns\SyncsAffiliateIntegrations;
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
        $this->queueAgreementUpload();
    }

    protected function getCreatedNotification(): ?Notification
    {
        return Notification::make()
            ->success()
            ->title("Affiliate {$this->getRecord()->name} created")
            ->body('The Google Drive folder, Slack channel and Mollie customer are set up in the background. Problems show up under System → Integration errors.');
    }
}
