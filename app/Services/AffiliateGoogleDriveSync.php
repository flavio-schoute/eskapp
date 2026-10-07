<?php

namespace App\Services;

use App\Models\Affiliate;
use Illuminate\Http\UploadedFile;
use RuntimeException;

class AffiliateGoogleDriveSync
{
    public function __construct(public GoogleDrive $drive) {}

    /**
     * Make sure the affiliate has a Drive folder named after it, reusing an existing folder with the same name.
     */
    public function ensureFolder(Affiliate $affiliate): string
    {
        if (filled($affiliate->google_drive_folder_id)) {
            return $affiliate->google_drive_folder_id;
        }

        $this->guardConfigured();

        $parentId = config('services.google_drive.affiliates_folder_id');

        $folderId = $this->drive->findFolder($affiliate->name, $parentId)
            ?? $this->drive->createFolder($affiliate->name, $parentId);

        $affiliate->forceFill(['google_drive_folder_id' => $folderId])->saveQuietly();

        return $folderId;
    }

    /**
     * Rename the affiliate's Drive folder to match its current name.
     */
    public function renameFolder(Affiliate $affiliate): void
    {
        if (blank($affiliate->google_drive_folder_id)) {
            return;
        }

        $this->guardConfigured();

        $this->drive->rename($affiliate->google_drive_folder_id, $affiliate->name);
    }

    /**
     * Upload the agreement into the affiliate's Drive folder and store a reference to it.
     */
    public function uploadAgreement(Affiliate $affiliate, UploadedFile $file): void
    {
        $folderId = $this->ensureFolder($affiliate);

        $uploaded = $this->drive->upload(
            localPath: $file->getRealPath(),
            name: $file->getClientOriginalName(),
            mimeType: $file->getMimeType() ?? 'application/octet-stream',
            folderId: $folderId,
        );

        $affiliate->update([
            'agreement_file_name' => $uploaded['name'],
            'agreement_drive_file_id' => $uploaded['id'],
            'agreement_drive_url' => $uploaded['webViewLink'],
        ]);
    }

    private function guardConfigured(): void
    {
        if (! $this->drive->isConfigured() || blank(config('services.google_drive.affiliates_folder_id'))) {
            throw new RuntimeException('Google Drive is not configured.');
        }
    }
}
