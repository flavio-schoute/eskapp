<?php

namespace App\Observers;

use App\Enums\Integration;
use App\Models\Affiliate;
use App\Services\AffiliateGoogleDriveSync;
use App\Services\AffiliateMollieSync;
use App\Services\AffiliateSlackSync;
use App\Services\GoogleDrive;
use App\Services\IntegrationErrorLogger;
use App\Services\MollieCustomers;
use App\Services\Slack;
use Throwable;

class AffiliateObserver
{
    public function __construct(
        public GoogleDrive $drive,
        public AffiliateGoogleDriveSync $driveSync,
        public Slack $slack,
        public AffiliateSlackSync $slackSync,
        public MollieCustomers $mollie,
        public AffiliateMollieSync $mollieSync,
        public IntegrationErrorLogger $errorLogger,
    ) {}

    /**
     * Keep the affiliate's Google Drive folder, Slack channel and Mollie customer in sync, however it was saved.
     */
    public function saved(Affiliate $affiliate): void
    {
        $this->syncGoogleDrive($affiliate);
        $this->syncSlack($affiliate);
        $this->syncMollie($affiliate);
    }

    private function syncGoogleDrive(Affiliate $affiliate): void
    {
        if (! $this->drive->isConfigured()) {
            return;
        }

        if (blank($affiliate->google_drive_folder_id)) {
            $this->attempt(Integration::GoogleDrive, 'Create folder', $affiliate, fn () => $this->driveSync->ensureFolder($affiliate));
        } elseif ($affiliate->wasChanged('name')) {
            $this->attempt(Integration::GoogleDrive, 'Rename folder', $affiliate, fn () => $this->driveSync->renameFolder($affiliate));
        }
    }

    private function syncSlack(Affiliate $affiliate): void
    {
        if (! $this->slack->isConfigured()) {
            return;
        }

        if (blank($affiliate->slack_channel_id)) {
            $this->attempt(Integration::Slack, 'Create channel', $affiliate, fn () => $this->slackSync->ensureChannel($affiliate));
        } elseif ($affiliate->wasChanged(['name', 'type'])) {
            $this->attempt(Integration::Slack, 'Rename channel', $affiliate, fn () => $this->slackSync->renameChannel($affiliate));
        }
    }

    private function syncMollie(Affiliate $affiliate): void
    {
        if (! $this->mollie->isConfigured() || ! $this->mollieSync->shouldSync($affiliate)) {
            return;
        }

        if (blank($affiliate->mollie_customer_id)) {
            $this->attempt(Integration::Mollie, 'Create customer', $affiliate, fn () => $this->mollieSync->ensureCustomer($affiliate));
        } elseif ($affiliate->wasChanged([...AffiliateMollieSync::SyncedAttributes, 'payment_method'])) {
            $this->attempt(Integration::Mollie, 'Update customer', $affiliate, fn () => $this->mollieSync->updateCustomer($affiliate));
        }
    }

    /**
     * Run an integration step without failing the save, logging the error when it fails.
     */
    private function attempt(Integration $integration, string $action, Affiliate $affiliate, callable $step): void
    {
        try {
            $step();
        } catch (Throwable $exception) {
            $this->errorLogger->record($integration, $action, $exception, $affiliate);
        }
    }
}
