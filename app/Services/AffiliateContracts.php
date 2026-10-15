<?php

namespace App\Services;

use App\Enums\ContractStatus;
use App\Enums\Integration;
use App\Jobs\StoreSignedContract;
use App\Models\Affiliate;
use App\Models\AffiliateContract;
use App\Models\User;
use App\Support\ContractTerms;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Sends affiliate contracts for signing with eSignatures.com, follows their status and stores signed contracts
 * in the affiliate's Google Drive folder.
 */
class AffiliateContracts
{
    public function __construct(
        public ESignatures $eSignatures,
        public AffiliateGoogleDriveSync $driveSync,
    ) {}

    /**
     * Create the contract from the template, filled in with the agreed terms, and send it to the partner's signer by email.
     *
     * @param  array<string, mixed>  $terms  The filled-in contract form, see ContractTerms::placeholders().
     */
    public function send(Affiliate $affiliate, string $signerName, string $signerEmail, array $terms, ?User $creator = null): AffiliateContract
    {
        if (! $this->eSignatures->isConfigured()) {
            throw new RuntimeException('eSignatures is not configured.');
        }

        $templateId = config('services.esignatures.template_id');
        $usedPlaceholders = $this->eSignatures->templatePlaceholders($templateId);
        $isTest = (bool) config('services.esignatures.test_mode');

        $placeholderFields = collect(ContractTerms::placeholders($terms, $affiliate->invoice_country))
            ->only($usedPlaceholders)
            ->map(fn (string $value, string $key): array => ['placeholder_key' => $key, 'replace_with_text' => $value])
            ->values()
            ->all();

        $contract = $this->eSignatures->createContract(array_filter([
            'template_id' => $templateId,
            'title' => 'Samenwerkingsovereenkomst Affiliate Partner – '.ContractTerms::placeholders($terms)['partner_naam'],
            'locale' => 'nl',
            'metadata' => "affiliate:{$affiliate->getKey()}",
            'test' => $isTest ? 'yes' : 'no',
            'custom_webhook_url' => $this->webhookUrl(),
            'signers' => [
                [
                    'name' => $signerName,
                    'email' => $signerEmail,
                    'company_name' => ContractTerms::placeholders($terms)['partner_naam'],
                    'signing_order' => '1',
                    'signature_request_delivery_methods' => ['email'],
                    'signed_document_delivery_method' => 'email',
                ],
                [
                    'name' => config('services.esignatures.eskool_signer.name'),
                    'email' => config('services.esignatures.eskool_signer.email'),
                    'company_name' => 'e-Skool B.V.',
                    'signing_order' => '2',
                    'auto_sign' => 'yes',
                    'signature_request_delivery_methods' => [],
                    'signed_document_delivery_method' => 'email',
                ],
            ],
            'placeholder_fields' => $placeholderFields,
        ], fn (mixed $value): bool => filled($value)));

        return $affiliate->contracts()->create([
            'created_by' => $creator?->getKey(),
            'esignatures_contract_id' => $contract['id'],
            'status' => ContractStatus::Sent,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'sign_page_url' => collect($contract['signers'] ?? [])->firstWhere('email', $signerEmail)['sign_page_url'] ?? null,
            'is_test' => $isTest,
            'fields' => collect($terms)->except(['signer_name', 'signer_email', 'affiliate_id'])->all(),
        ]);
    }

    /**
     * Process an eSignatures webhook event for one of our contracts.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(array $payload): void
    {
        $contractId = data_get($payload, 'data.contract.id') ?? data_get($payload, 'data.contract_id');
        $contract = $contractId ? AffiliateContract::firstWhere('esignatures_contract_id', $contractId) : null;

        if (! $contract) {
            return;
        }

        match ($payload['status'] ?? null) {
            'signer-viewed-the-contract' => $this->markViewed($contract),
            'contract-signed' => $this->markSigned($contract),
            'signer-declined' => $contract->update(['status' => ContractStatus::Declined]),
            'contract-withdrawn' => $contract->update(['status' => ContractStatus::Withdrawn]),
            'error' => app(IntegrationErrorLogger::class)->record(
                Integration::ESignatures,
                'Send contract',
                new RuntimeException(data_get($payload, 'data.error_message') ?: data_get($payload, 'data.error_code', 'Unknown error')),
                $contract->affiliate,
            ),
            default => null,
        };
    }

    /**
     * Ask eSignatures for the current status of a contract that is still out for signature.
     */
    public function refreshStatus(AffiliateContract $contract): AffiliateContract
    {
        $remote = $this->eSignatures->contract($contract->esignatures_contract_id);

        match ($remote['status'] ?? null) {
            'signed' => $this->markSigned($contract),
            'withdrawn' => $contract->update(['status' => ContractStatus::Withdrawn]),
            default => null,
        };

        $contract->update(['status_checked_at' => now()]);

        return $contract;
    }

    /**
     * Download the signed contract and store it in the affiliate's Drive folder as its agreement document.
     */
    public function storeSignedPdf(AffiliateContract $contract): void
    {
        if (filled($contract->drive_file_id)) {
            return;
        }

        $pdfUrl = $this->eSignatures->contract($contract->esignatures_contract_id)['contract_pdf_url'] ?? null;

        if (blank($pdfUrl)) {
            throw new RuntimeException('eSignatures has no signed PDF for this contract yet.');
        }

        $affiliate = $contract->affiliate;
        $folderId = $this->driveSync->ensureFolder($affiliate);
        $fileName = 'Samenwerkingsovereenkomst '.$affiliate->name.' (getekend '.($contract->signed_at ?? now())->format('Y-m-d').').pdf';
        $localPath = tempnam(sys_get_temp_dir(), 'contract');

        try {
            file_put_contents($localPath, $this->eSignatures->download($pdfUrl));

            $uploaded = $this->driveSync->drive->upload($localPath, $fileName, 'application/pdf', $folderId);
        } finally {
            @unlink($localPath);
        }

        $contract->update([
            'drive_file_id' => $uploaded['id'],
            'drive_url' => $uploaded['webViewLink'],
        ]);

        $affiliate->update([
            'agreement_file_name' => $uploaded['name'],
            'agreement_drive_file_id' => $uploaded['id'],
            'agreement_drive_url' => $uploaded['webViewLink'],
        ]);
    }

    private function markViewed(AffiliateContract $contract): void
    {
        if ($contract->status === ContractStatus::Sent) {
            $contract->update(['status' => ContractStatus::Viewed, 'viewed_at' => now()]);
        }
    }

    private function markSigned(AffiliateContract $contract): void
    {
        if ($contract->status !== ContractStatus::Signed) {
            $contract->update(['status' => ContractStatus::Signed, 'signed_at' => now()]);
        }

        if (blank($contract->drive_file_id)) {
            StoreSignedContract::dispatch($contract->getKey())->afterCommit();
        }
    }

    /**
     * The webhook URL eSignatures reports status changes to, only when this app can be reached from the internet.
     */
    private function webhookUrl(): ?string
    {
        $url = route('webhooks.esignatures');
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (! str_starts_with($url, 'https://') || $host === 'localhost' || Str::endsWith($host, ['.test', '.localhost'])) {
            return null;
        }

        return $url;
    }
}
