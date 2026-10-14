<?php

namespace App\Observers;

use App\Jobs\SyncAffiliateIntegrations;
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
}
