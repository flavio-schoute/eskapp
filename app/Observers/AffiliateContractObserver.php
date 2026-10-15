<?php

namespace App\Observers;

use App\Enums\ContractStatus;
use App\Models\ActivityLog;
use App\Models\AffiliateContract;

class AffiliateContractObserver
{
    public function created(AffiliateContract $contract): void
    {
        ActivityLog::record($contract->affiliate, 'contract_sent', "Sent the contract to {$contract->signer_name} ({$contract->signer_email})");
    }

    public function updated(AffiliateContract $contract): void
    {
        if (! $contract->wasChanged('status')) {
            return;
        }

        ActivityLog::record($contract->affiliate, 'contract_status', match ($contract->status) {
            ContractStatus::Viewed => "{$contract->signer_name} opened the contract",
            ContractStatus::Signed => "{$contract->signer_name} signed the contract",
            ContractStatus::Declined => "{$contract->signer_name} declined the contract",
            ContractStatus::Withdrawn => 'The contract was withdrawn',
            ContractStatus::Sent => 'The contract was sent again',
        });
    }
}
