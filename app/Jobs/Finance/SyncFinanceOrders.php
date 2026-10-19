<?php

namespace App\Jobs\Finance;

use App\Enums\Integration;
use App\Services\Finance\OrderSync;
use App\Services\Finance\PaymentMatcher;
use App\Services\IntegrationErrorLogger;
use App\Services\PlugAndPay;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Syncs the Plug&Pay orders for Finance in the background: recent ones every hour, all of them every night.
 */
class SyncFinanceOrders implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    /**
     * @param  int|null  $days  Only orders invoiced in the last this many days; null syncs all orders.
     */
    public function __construct(public ?int $days = 120) {}

    public function uniqueId(): string
    {
        return $this->days === null ? 'full' : "recent-{$this->days}";
    }

    public function handle(PlugAndPay $plugAndPay, OrderSync $sync, PaymentMatcher $matcher, IntegrationErrorLogger $errorLogger): void
    {
        if (! $plugAndPay->isConfigured()) {
            return;
        }

        try {
            $sync->sync($this->days === null ? null : now()->subDays($this->days));
            // An order paid since the last sync turns its bank line from "staat open" into "betaald".
            $matcher->matchIncoming();
        } catch (Throwable $exception) {
            $errorLogger->record(Integration::PlugAndPay, 'Sync Finance orders', $exception);
        }
    }
}
