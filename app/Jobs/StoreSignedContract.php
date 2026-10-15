<?php

namespace App\Jobs;

use App\Enums\Integration;
use App\Models\AffiliateContract;
use App\Services\AffiliateContracts;
use App\Services\IntegrationErrorLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * Stores a signed contract's PDF in the affiliate's Google Drive folder.
 */
class StoreSignedContract implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [60, 300];

    public function __construct(public int $contractId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("contract-{$this->contractId}"))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(AffiliateContracts $contracts): void
    {
        $contract = AffiliateContract::find($this->contractId);

        if ($contract) {
            $contracts->storeSignedPdf($contract);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(IntegrationErrorLogger::class)->record(
            Integration::GoogleDrive,
            'Store signed contract',
            $exception,
            AffiliateContract::find($this->contractId)?->affiliate,
        );
    }
}
