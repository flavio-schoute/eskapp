<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleDrive
{
    public const FolderMimeType = 'application/vnd.google-apps.folder';

    private const TokenUrl = 'https://oauth2.googleapis.com/token';

    private const FilesUrl = 'https://www.googleapis.com/drive/v3/files';

    private const UploadUrl = 'https://www.googleapis.com/upload/drive/v3/files';

    /**
     * Determine whether the Google Drive credentials are configured.
     */
    public function isConfigured(): bool
    {
        return filled(config('services.google_drive.client_id'))
            && filled(config('services.google_drive.client_secret'))
            && filled(config('services.google_drive.refresh_token'));
    }

    /**
     * Find a folder with the given name inside the parent folder.
     */
    public function findFolder(string $name, string $parentId): ?string
    {
        $escapedName = str_replace(['\\', "'"], ['\\\\', "\\'"], $name);

        $files = $this->request()
            ->get(self::FilesUrl, [
                'q' => "name = '{$escapedName}' and '{$parentId}' in parents and mimeType = '".self::FolderMimeType."' and trashed = false",
                'fields' => 'files(id)',
                'pageSize' => 1,
                'supportsAllDrives' => 'true',
                'includeItemsFromAllDrives' => 'true',
            ])
            ->throw()
            ->json('files', []);

        return $files[0]['id'] ?? null;
    }

    /**
     * Create a folder inside the parent folder and return its ID.
     */
    public function createFolder(string $name, string $parentId): string
    {
        return $this->request()
            ->withQueryParameters(['supportsAllDrives' => 'true', 'fields' => 'id'])
            ->post(self::FilesUrl, [
                'name' => $name,
                'mimeType' => self::FolderMimeType,
                'parents' => [$parentId],
            ])
            ->throw()
            ->json('id');
    }

    /**
     * Rename an existing file or folder.
     */
    public function rename(string $fileId, string $name): void
    {
        $this->request()
            ->withQueryParameters(['supportsAllDrives' => 'true'])
            ->patch(self::FilesUrl."/{$fileId}", ['name' => $name])
            ->throw();
    }

    /**
     * Upload a local file into the given folder.
     *
     * @return array{id: string, name: string, webViewLink: string}
     */
    public function upload(string $localPath, string $name, string $mimeType, string $folderId): array
    {
        $sessionUrl = $this->request()
            ->withQueryParameters([
                'uploadType' => 'resumable',
                'supportsAllDrives' => 'true',
                'fields' => 'id,name,webViewLink',
            ])
            ->withHeaders(['X-Upload-Content-Type' => $mimeType])
            ->post(self::UploadUrl, [
                'name' => $name,
                'parents' => [$folderId],
            ])
            ->throw()
            ->header('Location');

        return $this->request()
            ->withBody(file_get_contents($localPath), $mimeType)
            ->put($sessionUrl)
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::withToken($this->accessToken())
            ->acceptJson()
            ->timeout(60);
    }

    private function accessToken(): string
    {
        return Cache::remember('google-drive.access-token', now()->addMinutes(50), function (): string {
            return Http::asForm()
                ->post(self::TokenUrl, [
                    'client_id' => config('services.google_drive.client_id'),
                    'client_secret' => config('services.google_drive.client_secret'),
                    'refresh_token' => config('services.google_drive.refresh_token'),
                    'grant_type' => 'refresh_token',
                ])
                ->throw()
                ->json('access_token');
        });
    }
}
