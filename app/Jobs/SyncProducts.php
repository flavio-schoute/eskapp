<?php

namespace App\Jobs;

use App\Enums\Integration;
use App\Services\IntegrationErrorLogger;
use App\Services\PlugAndPay;
use App\Services\ProductSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Syncs the Plug&Pay products in the background: counting the orders per product reads all invoiced orders.
 */
class SyncProducts implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(PlugAndPay $plugAndPay, ProductSync $sync, IntegrationErrorLogger $errorLogger): void
    {
        if (! $plugAndPay->isConfigured()) {
            return;
        }

        try {
            $sync->sync();
        } catch (Throwable $exception) {
            $errorLogger->record(Integration::PlugAndPay, 'Sync products', $exception);
        }
    }
}
