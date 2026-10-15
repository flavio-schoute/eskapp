<?php

namespace App\Console\Commands;

use App\Enums\Integration;
use App\Models\ActivityLog;
use App\Models\AffiliateContract;
use App\Services\ESignatures;
use App\Services\IntegrationErrorLogger;
use App\Services\Slack;
use App\Support\ContractReminder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('contracts:send-reminders')]
#[Description('Remind partners who have not signed their contract after a few days, and tell the team in Slack')]
class SendContractReminders extends Command
{
    public function handle(ESignatures $eSignatures, Slack $slack, IntegrationErrorLogger $errorLogger): int
    {
        $settings = ContractReminder::settings();

        if (! $settings['enabled']) {
            return self::SUCCESS;
        }

        $contracts = AffiliateContract::query()
            ->with('affiliate')
            ->open()
            ->whereNull('reminded_at')
            ->where('created_at', '<=', now()->subDays($settings['days']))
            ->get();

        foreach ($contracts as $contract) {
            if ($settings['remind_partner'] && $eSignatures->isConfigured() && filled($contract->signerId())) {
                try {
                    $eSignatures->resendToSigner($contract->esignatures_contract_id, $contract->signerId());
                } catch (Throwable $exception) {
                    $errorLogger->record(Integration::ESignatures, 'Send contract reminder', $exception, $contract->affiliate);
                }
            }

            if ($slack->isConfigured() && filled($settings['channel'])) {
                try {
                    $slack->postMessage($settings['channel'], ContractReminder::render($settings['message'], $contract));
                } catch (Throwable $exception) {
                    $errorLogger->record(Integration::Slack, 'Post contract reminder', $exception, $contract->affiliate);
                }
            }

            $contract->update(['reminded_at' => now()]);

            ActivityLog::record($contract->affiliate, 'contract_reminder', "Reminded {$contract->signer_name} to sign the contract");
        }

        $this->info("Sent {$contracts->count()} contract reminders.");

        return self::SUCCESS;
    }
}
