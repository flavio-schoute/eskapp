<?php

namespace App\Jobs;

use App\Enums\Integration;
use App\Models\Affiliate;
use App\Services\AffiliateGoogleDriveSync;
use App\Services\AffiliateMollieSync;
use App\Services\AffiliateSlackSync;
use App\Services\GoogleDrive;
use App\Services\IntegrationErrorLogger;
use App\Services\MollieCustomers;
use App\Services\Slack;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Keeps an affiliate's Google Drive folder, Slack channel and Mollie customer in sync after it was saved,
 * in the background so saving does not wait for these services.
 */
class SyncAffiliateIntegrations implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<string>  $changedAttributes  The attributes that changed in the save that started this job.
     */
    public function __construct(
        public int $affiliateId,
        public array $changedAttributes = [],
    ) {}

    /**
     * One job per affiliate at a time, so two saves never create two folders or channels.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("affiliate-{$this->affiliateId}"))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(
        GoogleDrive $drive,
        AffiliateGoogleDriveSync $driveSync,
        Slack $slack,
        AffiliateSlackSync $slackSync,
        MollieCustomers $mollie,
        AffiliateMollieSync $mollieSync,
        IntegrationErrorLogger $errorLogger,
    ): void {
        $affiliate = Affiliate::find($this->affiliateId);

        if (! $affiliate) {
            return;
        }

        $attempt = function (Integration $integration, string $action, callable $step) use ($affiliate, $errorLogger): void {
            try {
                $step();
            } catch (Throwable $exception) {
                $errorLogger->record($integration, $action, $exception, $affiliate);
            }
        };

        if ($drive->isConfigured()) {
            if (blank($affiliate->google_drive_folder_id)) {
                $attempt(Integration::GoogleDrive, 'Create folder', fn () => $driveSync->ensureFolder($affiliate));
            } elseif ($this->changed('name')) {
                $attempt(Integration::GoogleDrive, 'Rename folder', fn () => $driveSync->renameFolder($affiliate));
            }
        }

        if ($slack->isConfigured()) {
            if (blank($affiliate->slack_channel_id)) {
                $attempt(Integration::Slack, 'Create channel', fn () => $slackSync->ensureChannel($affiliate));
            } else {
                if ($this->changed('name', 'type')) {
                    $attempt(Integration::Slack, 'Rename channel', fn () => $slackSync->renameChannel($affiliate));
                }

                if ($this->changed('slack_contact_member_id') && filled($affiliate->slack_contact_member_id)) {
                    $attempt(Integration::Slack, 'Invite contact', fn () => $slackSync->inviteContact($affiliate));
                }
            }
        }

        if ($mollie->isConfigured() && $mollieSync->shouldSync($affiliate)) {
            if (blank($affiliate->mollie_customer_id)) {
                $attempt(Integration::Mollie, 'Create customer', fn () => $mollieSync->ensureCustomer($affiliate));
            } elseif ($this->changed(...AffiliateMollieSync::SyncedAttributes, ...['payment_method'])) {
                $attempt(Integration::Mollie, 'Update customer', fn () => $mollieSync->updateCustomer($affiliate));
            }
        }
    }

    private function changed(string ...$attributes): bool
    {
        return array_intersect($attributes, $this->changedAttributes) !== [];
    }
}
