<?php

use App\Enums\AffiliatePaymentMethod;
use App\Enums\AffiliateStatus;
use App\Enums\AffiliateType;
use App\Filament\Resources\Affiliates\Pages\CreateAffiliate;
use App\Filament\Resources\Affiliates\Pages\EditAffiliate;
use App\Filament\Resources\Affiliates\Pages\ListAffiliates;
use App\Filament\Resources\Affiliates\Widgets\PipelineAffiliates;
use App\Models\Affiliate;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

const ParentFolderId = 'parent-folder-id';

beforeEach(function () {
    config([
        'services.google_drive.client_id' => 'client-id',
        'services.google_drive.client_secret' => 'client-secret',
        'services.google_drive.refresh_token' => 'refresh-token',
        'services.google_drive.affiliates_folder_id' => ParentFolderId,
        'services.slack.notifications.bot_user_oauth_token' => null,
    ]);

    Http::preventStrayRequests();

    actingAs(User::factory()->create());
});

/**
 * Fake the Google Drive API, optionally returning an existing folder from the folder search.
 */
function fakeGoogleDrive(?string $existingFolderId = null): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token', 'expires_in' => 3599]),
        'www.googleapis.com/drive/v3/files?*' => fn (Request $request) => $request->method() === 'GET'
            ? Http::response(['files' => $existingFolderId ? [['id' => $existingFolderId]] : []])
            : Http::response(['id' => 'new-folder-id']),
        'www.googleapis.com/drive/v3/files/*' => Http::response(['id' => 'existing-folder-id']),
        'www.googleapis.com/upload/drive/v3/files?*' => Http::response(headers: ['Location' => 'https://www.googleapis.com/upload/session?upload_id=abc']),
        'www.googleapis.com/upload/session?*' => Http::response([
            'id' => 'agreement-file-id',
            'name' => 'agreement.pdf',
            'webViewLink' => 'https://drive.google.com/file/d/agreement-file-id/view',
        ]),
    ]);
}

/**
 * @return array<string, mixed>
 */
function validAffiliateFormData(array $overrides = []): array
{
    return [
        'name' => 'Acme Partners',
        'type' => AffiliateType::Affiliate->value,
        'status' => AffiliateStatus::Active->value,
        'login_url' => 'https://dashboard.acme.test',
        'username' => 'eskapp',
        'password' => 'secret-password',
        'payment_method' => AffiliatePaymentMethod::Automatic->value,
        ...$overrides,
    ];
}

it('creates a drive folder named after the affiliate and uploads the agreement', function () {
    fakeGoogleDrive();

    Livewire::test(CreateAffiliate::class)
        ->fillForm(validAffiliateFormData([
            'agreement_upload' => UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf'),
        ]))
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotNotified('Saved, but Google Drive sync failed');

    $affiliate = Affiliate::sole();

    expect($affiliate->google_drive_folder_id)->toBe('new-folder-id')
        ->and($affiliate->agreement_drive_file_id)->toBe('agreement-file-id')
        ->and($affiliate->agreement_file_name)->toBe('agreement.pdf')
        ->and($affiliate->agreement_drive_url)->toBe('https://drive.google.com/file/d/agreement-file-id/view');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files')
        && $request['name'] === 'Acme Partners'
        && $request['mimeType'] === 'application/vnd.google-apps.folder'
        && $request['parents'] === [ParentFolderId]);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/upload/drive/v3/files')
        && $request['parents'] === ['new-folder-id']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://www.googleapis.com/upload/session?upload_id=abc');
});

it('reuses an existing drive folder with the same name', function () {
    fakeGoogleDrive(existingFolderId: 'existing-folder-id');

    Livewire::test(CreateAffiliate::class)
        ->fillForm(validAffiliateFormData())
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Affiliate::sole()->google_drive_folder_id)->toBe('existing-folder-id');

    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST'
        && str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files'));
});

it('uploads a new agreement into the existing folder when editing', function () {
    fakeGoogleDrive();

    $affiliate = Affiliate::factory()->create(['google_drive_folder_id' => 'existing-folder-id']);

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->fillForm([
            'agreement_upload' => UploadedFile::fake()->create('agreement.pdf', 100, 'application/pdf'),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($affiliate->refresh()->agreement_drive_file_id)->toBe('agreement-file-id');

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/upload/drive/v3/files')
        && $request['parents'] === ['existing-folder-id']);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
});

it('creates a missing drive folder when an affiliate is saved', function () {
    fakeGoogleDrive();

    $affiliate = Affiliate::factory()->create(['google_drive_folder_id' => null]);

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($affiliate->refresh()->google_drive_folder_id)->toBe('new-folder-id');
});

it('creates a missing drive folder when a pipeline affiliate is made active', function () {
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->pipeline()->create(['google_drive_folder_id' => null]));

    fakeGoogleDrive();

    Livewire::test(PipelineAffiliates::class)
        ->callAction(TestAction::make('activate')->table($affiliate));

    expect($affiliate->refresh()->google_drive_folder_id)->toBe('new-folder-id');
});

it('creates a missing drive folder when the status is changed in the list', function () {
    $affiliate = Affiliate::withoutEvents(fn () => Affiliate::factory()->create(['google_drive_folder_id' => null]));

    fakeGoogleDrive();

    Livewire::test(ListAffiliates::class)
        ->call('updateTableColumnState', 'status', (string) $affiliate->getKey(), 'on_hold');

    expect($affiliate->refresh()->google_drive_folder_id)->toBe('new-folder-id');
});

it('creates a drive folder for affiliates created outside the form', function () {
    fakeGoogleDrive();

    $affiliate = Affiliate::factory()->create(['google_drive_folder_id' => null]);

    expect($affiliate->refresh()->google_drive_folder_id)->toBe('new-folder-id');
});

it('still saves the affiliate when google drive is unreachable', function () {
    Http::fake(['*' => Http::response(status: 500)]);

    $affiliate = Affiliate::factory()->create(['google_drive_folder_id' => null]);

    expect($affiliate->exists)->toBeTrue()
        ->and($affiliate->refresh()->google_drive_folder_id)->toBeNull();
});

it('renames the drive folder when the affiliate name changes', function () {
    fakeGoogleDrive();

    $affiliate = Affiliate::factory()->create(['google_drive_folder_id' => 'existing-folder-id']);

    Livewire::test(EditAffiliate::class, ['record' => $affiliate->getRouteKey()])
        ->fillForm(['name' => 'Renamed Partner'])
        ->call('save')
        ->assertHasNoFormErrors();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === 'https://www.googleapis.com/drive/v3/files/existing-folder-id?supportsAllDrives=true'
        && $request['name'] === 'Renamed Partner');
});

it('creates the drive folder even when no agreement is uploaded', function () {
    fakeGoogleDrive();

    Livewire::test(CreateAffiliate::class)
        ->fillForm(validAffiliateFormData())
        ->call('create')
        ->assertHasNoFormErrors();

    $affiliate = Affiliate::sole();

    expect($affiliate->google_drive_folder_id)->toBe('new-folder-id')
        ->and($affiliate->agreement_drive_file_id)->toBeNull();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/upload/'));
});

it('still saves the affiliate and warns when google drive is not configured', function () {
    config(['services.google_drive.refresh_token' => null]);

    Livewire::test(CreateAffiliate::class)
        ->fillForm(validAffiliateFormData())
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified('Saved, but Google Drive sync failed');

    expect(Affiliate::sole()->google_drive_folder_id)->toBeNull();

    Http::assertNothingSent();
});

it('only accepts pdf agreement files', function () {
    fakeGoogleDrive();

    Livewire::test(CreateAffiliate::class)
        ->fillForm(validAffiliateFormData([
            'agreement_upload' => UploadedFile::fake()->create('agreement.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ]))
        ->call('create')
        ->assertHasFormErrors(['agreement_upload']);

    expect(Affiliate::count())->toBe(0);
});
