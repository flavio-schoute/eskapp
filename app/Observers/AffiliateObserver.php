<?php

namespace App\Observers;

use App\Jobs\SyncAffiliateIntegrations;
use App\Models\ActivityLog;
use App\Models\Affiliate;

class AffiliateObserver
{
    /**
     * Sync the affiliate's Google Drive folder, Slack channel and Mollie customer in the background, however it was saved.
     */
    public function saved(Affiliate $affiliate): void
    {
        SyncAffiliateIntegrations::dispatch($affiliate->getKey(), array_keys($affiliate->getChanges()))->afterCommit();
    }

    public function created(Affiliate $affiliate): void
    {
        ActivityLog::record($affiliate, 'created', 'Created the affiliate');
    }

    public function updated(Affiliate $affiliate): void
    {
        $changes = ActivityLog::changesOf($affiliate);

        if ($changes === []) {
            return;
        }

        $description = array_keys($changes) === ['Status']
            ? "Changed the status to {$changes['Status']['new']}"
            : 'Updated '.collect(array_keys($changes))->join(', ', ' and ');

        ActivityLog::record($affiliate, 'updated', $description, $changes);
    }

    public function deleted(Affiliate $affiliate): void
    {
        ActivityLog::record($affiliate, 'deleted', 'Deleted the affiliate');
    }
}
