<?php

namespace App\Filament\Resources\Affiliates\Pages\Concerns;

use App\Enums\Integration;
use App\Models\Affiliate;
use App\Services\AffiliateGoogleDriveSync;
use App\Services\AffiliateMollieSync;
use App\Services\AffiliateSlackSync;
use App\Services\IntegrationErrorLogger;
use Closure;
use Filament\Notifications\Notification;
use Illuminate\Support\Arr;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

trait SyncsAffiliateIntegrations
{
    protected ?TemporaryUploadedFile $pendingAgreementUpload = null;

    /**
     * Take the agreement upload out of the form data, so it is not saved on the model.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function pullAgreementUpload(array $data): array
    {
        $upload = Arr::first(Arr::wrap(Arr::pull($data, 'agreement_upload')));

        $this->pendingAgreementUpload = $upload instanceof TemporaryUploadedFile ? $upload : null;

        return $data;
    }

    /**
     * Make sure the Drive folder, Slack channel and (for invoiced affiliates) Mollie customer exist, and upload a pending agreement.
     */
    protected function syncIntegrations(): void
    {
        $hasFolder = $this->runIntegrationStep(Integration::GoogleDrive, 'Create folder', function (Affiliate $affiliate): void {
            app(AffiliateGoogleDriveSync::class)->ensureFolder($affiliate);
        });

        if ($hasFolder && $this->pendingAgreementUpload) {
            $this->runIntegrationStep(Integration::GoogleDrive, 'Upload agreement', function (Affiliate $affiliate): void {
                app(AffiliateGoogleDriveSync::class)->uploadAgreement($affiliate, $this->pendingAgreementUpload);
            });
        }

        $this->runIntegrationStep(Integration::Slack, 'Create channel', function (Affiliate $affiliate): void {
            app(AffiliateSlackSync::class)->ensureChannel($affiliate);
        });

        if (app(AffiliateMollieSync::class)->shouldSync($this->getRecord())) {
            $this->runIntegrationStep(Integration::Mollie, 'Create customer', function (Affiliate $affiliate): void {
                app(AffiliateMollieSync::class)->ensureCustomer($affiliate);
            });
        }
    }

    /**
     * Run an integration step without blocking the save; when it fails, log it and warn the user.
     *
     * @param  Closure(Affiliate): void  $callback
     */
    protected function runIntegrationStep(Integration $integration, string $action, Closure $callback): bool
    {
        try {
            $callback($this->getRecord());

            return true;
        } catch (Throwable $exception) {
            app(IntegrationErrorLogger::class)->record($integration, $action, $exception, $this->getRecord());

            Notification::make()
                ->warning()
                ->title("Saved, but {$integration->getLabel()} sync failed")
                ->body("{$action}: {$exception->getMessage()} The error is logged under System → Integration errors.")
                ->persistent()
                ->send();

            return false;
        }
    }
}
