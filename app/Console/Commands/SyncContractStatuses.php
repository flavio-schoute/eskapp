<?php

namespace App\Console\Commands;

use App\Enums\ContractStatus;
use App\Enums\Integration;
use App\Jobs\StoreSignedContract;
use App\Models\AffiliateContract;
use App\Services\AffiliateContracts;
use App\Services\ESignatures;
use App\Services\IntegrationErrorLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('contracts:sync-status')]
#[Description('Check eSignatures for contracts that are still waiting for a signature, in case a webhook was missed')]
class SyncContractStatuses extends Command
{
    public function handle(ESignatures $eSignatures, AffiliateContracts $contracts, IntegrationErrorLogger $errorLogger): int
    {
        if (! $eSignatures->isConfigured()) {
            $this->info('eSignatures is not configured.');

            return self::SUCCESS;
        }

        $openContracts = AffiliateContract::query()->with('affiliate')->open()->get();
        $signed = 0;

        foreach ($openContracts as $contract) {
            try {
                if ($contracts->refreshStatus($contract)->status === ContractStatus::Signed) {
                    $signed++;
                }
            } catch (Throwable $exception) {
                $errorLogger->record(Integration::ESignatures, 'Check contract status', $exception, $contract->affiliate);
            }
        }

        AffiliateContract::query()->signedWithoutDriveFile()->whereKeyNot($openContracts->modelKeys())->each(
            fn (AffiliateContract $contract) => StoreSignedContract::dispatch($contract->getKey()),
        );

        $this->info("Checked {$openContracts->count()} open contracts, {$signed} now signed.");

        return self::SUCCESS;
    }
}
