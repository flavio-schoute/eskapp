<?php

namespace App\Jobs;

use App\Enums\Integration;
use App\Models\Affiliate;
use App\Services\AffiliateGoogleDriveSync;
use App\Services\IntegrationErrorLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Uploads an affiliate's agreement PDF to its Google Drive folder in the background. The file is kept on the
 * local disk until the upload succeeded (or finally failed), then removed.
 */
class UploadAffiliateAgreement implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    public function __construct(
        public int $affiliateId,
        public string $path,
        public string $originalName,
    ) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("affiliate-{$this->affiliateId}"))->releaseAfter(10)->expireAfter(300)];
    }

    public function handle(AffiliateGoogleDriveSync $driveSync): void
    {
        $affiliate = Affiliate::find($this->affiliateId);

        if (! $affiliate || ! Storage::disk('local')->exists($this->path)) {
            return;
        }

        $driveSync->uploadAgreement($affiliate, new UploadedFile(
            Storage::disk('local')->path($this->path),
            $this->originalName,
            'application/pdf',
            null,
            true,
        ));

        Storage::disk('local')->delete($this->path);
    }

    public function failed(Throwable $exception): void
    {
        app(IntegrationErrorLogger::class)->record(Integration::GoogleDrive, 'Upload agreement', $exception, Affiliate::find($this->affiliateId));

        Storage::disk('local')->delete($this->path);
    }
}
