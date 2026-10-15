<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Client for the eSignatures.com API (https://esignatures.com/docs/api).
 */
class ESignatures
{
    private const ApiUrl = 'https://esignatures.com/api';

    /**
     * Determine whether a secret token and a contract template are configured.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.esignatures.secret_token')) && filled(config('services.esignatures.template_id'));
    }

    /**
     * Create a contract from a template and send it to its signers.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The created contract, including its signers and their sign page URLs.
     */
    public function createContract(array $payload): array
    {
        return $this->request()->post(self::ApiUrl.'/contracts', $payload)->throw()->json('data.contract');
    }

    /**
     * Get a contract, including its status and (once signed) a temporary link to the signed PDF.
     *
     * @return array<string, mixed>
     */
    public function contract(string $contractId): array
    {
        return $this->request()->get(self::ApiUrl."/contracts/{$contractId}")->throw()->json('data.contract');
    }

    /**
     * Send the signature request to a signer again, as a reminder.
     */
    public function resendToSigner(string $contractId, string $signerId): void
    {
        $this->request()->post(self::ApiUrl."/contracts/{$contractId}/signers/{$signerId}/send_contract")->throw();
    }

    /**
     * Get the placeholder keys used in a template, cached for a few minutes.
     *
     * @return list<string>
     */
    public function templatePlaceholders(string $templateId): array
    {
        return Cache::remember("esignatures.template.{$templateId}.placeholders", now()->addMinutes(10), function () use ($templateId): array {
            return $this->request()->get(self::ApiUrl."/templates/{$templateId}")->throw()->json('data.placeholder_fields') ?? [];
        });
    }

    /**
     * Download a file eSignatures links to, such as the signed contract PDF.
     */
    public function download(string $url): string
    {
        return Http::timeout(60)->get($url)->throw()->body();
    }

    /**
     * Whether a webhook request really comes from eSignatures: its body is signed with the secret token.
     */
    public function hasValidSignature(string $body, ?string $signature): bool
    {
        $secretToken = config('services.esignatures.secret_token');

        if (blank($signature) || blank($secretToken)) {
            return false;
        }

        $expected = hash_hmac('sha256', $body, $secretToken, true);

        return hash_equals(bin2hex($expected), strtolower($signature))
            || hash_equals(base64_encode($expected), $signature);
    }

    private function request(): PendingRequest
    {
        return Http::withBasicAuth(config('services.esignatures.secret_token'), '')
            ->acceptJson()
            ->asJson()
            ->timeout(30);
    }
}
