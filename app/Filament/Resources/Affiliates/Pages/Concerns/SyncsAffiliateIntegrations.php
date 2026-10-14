<?php

namespace App\Filament\Resources\Affiliates\Pages\Concerns;

use App\Jobs\UploadAffiliateAgreement;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Google Drive, Slack and Mollie are synced in the background after every save (see SyncAffiliateIntegrations).
 * The create and edit pages only hand an uploaded agreement over to a background upload.
 */
trait SyncsAffiliateIntegrations
{
    protected ?TemporaryUploadedFile $pendingAgreementUpload = null;

    /**
     * Take the agreement upload out of the form data, so it is not saved on the model.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function pullAgreementUpload(array $data): array
    {
        $upload = Arr::first(Arr::wrap(Arr::pull($data, 'agreement_upload')));

        $this->pendingAgreementUpload = $upload instanceof TemporaryUploadedFile ? $upload : null;

        return $data;
    }

    /**
     * Keep the uploaded agreement on the local disk and upload it to Google Drive in the background.
     */
    protected function queueAgreementUpload(): void
    {
        if (! $this->pendingAgreementUpload) {
            return;
        }

        $path = $this->pendingAgreementUpload->storeAs('agreement-uploads', Str::uuid().'.pdf', 'local');

        UploadAffiliateAgreement::dispatch(
            $this->getRecord()->getKey(),
            $path,
            $this->pendingAgreementUpload->getClientOriginalName(),
        )->afterCommit();

        $this->pendingAgreementUpload = null;
    }
}
