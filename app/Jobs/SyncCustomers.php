<?php

namespace App\Jobs;

use App\Enums\Integration;
use App\Services\CustomerSync;
use App\Services\IntegrationErrorLogger;
use App\Services\PlugAndPay;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Rebuilds the customer list from Plug&Pay and Mollie in the background: it reads a few thousand orders and
 * customers, which takes about a minute.
 */
class SyncCustomers implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(PlugAndPay $plugAndPay, CustomerSync $sync, IntegrationErrorLogger $errorLogger): void
    {
        if (! $plugAndPay->isConfigured()) {
            return;
        }

        try {
            $sync->sync();
        } catch (Throwable $exception) {
            $errorLogger->record(Integration::PlugAndPay, 'Sync customers', $exception);
        }
    }
}
