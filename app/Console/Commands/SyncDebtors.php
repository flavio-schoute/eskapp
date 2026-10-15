<?php

namespace App\Console\Commands;

use App\Enums\Integration;
use App\Services\DebtorSync;
use App\Services\IntegrationErrorLogger;
use App\Services\PlugAndPay;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('debtors:sync')]
#[Description('Fetch the unpaid and charged back orders from Plug&Pay for debiteurenbeheer')]
class SyncDebtors extends Command
{
    public function handle(PlugAndPay $plugAndPay, DebtorSync $sync, IntegrationErrorLogger $errorLogger): int
    {
        if (! $plugAndPay->isConfigured()) {
            $this->info('Plug&Pay is not configured.');

            return self::SUCCESS;
        }

        try {
            $result = $sync->sync();
        } catch (Throwable $exception) {
            $errorLogger->record(Integration::PlugAndPay, 'Sync debtors', $exception);

            return self::FAILURE;
        }

        $this->info("{$result['open']} open debtor cases, {$result['resolved']} resolved.");

        return self::SUCCESS;
    }
}
