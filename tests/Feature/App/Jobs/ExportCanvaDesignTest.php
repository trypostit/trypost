<?php

declare(strict_types=1);

use App\Dto\ImportedFile;
use App\Jobs\Media\ExportCanvaDesign;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\Account;
use App\Models\Media;
use App\Models\MediaSourceConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\Sources\CanvaClient;
use App\Support\MediaImportStatus;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();
    Sleep::fake();

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->account);

    config()->set([
        'services.canva.client_id' => 'canva-client-id',
        'services.canva.client_secret' => 'canva-client-secret',
        'trypost.media_sources.canva.enabled' => true,
        'trypost.media_sources.canva.api' => 'https://api.canva.test/rest/v1',
        'trypost.media_sources.canva.download_hosts' => 'export-download.canva.test',
        'trypost.media_sources.canva.export_timeout_seconds' => 120,
        'trypost.media_sources.canva.export_poll_seconds' => 2,
    ]);

    fakePublicDns();

    $this->connection = MediaSourceConnection::factory()->for($this->user)->create(['access_token' => 'canva-access-token']);
    $this->importId = (string) Str::uuid();
    MediaImportStatus::pending($this->importId, $this->user->id, $this->workspace->id);
});

function exportCanvaApi(string $path): string
{
    return config('trypost.media_sources.canva.api').$path;
}

function exportCanvaDownloadUrl(): string
{
    return 'https://export-download.canva.test/export/design.png?X-Amz-Signature=abc';
}

/**
 * @param  list<array<string, mixed>>  $polls
 */
function exportCanvaFake(array $polls, ?string $downloadUrl = null): void
{
    $sequence = Http::sequence();

    foreach ($polls as $poll) {
        $sequence->push(['job' => ['id' => 'export-job-1', ...$poll]]);
    }

    Http::fake([
        exportCanvaApi('/exports') => Http::response(['job' => ['id' => 'export-job-1', 'status' => 'in_progress']]),
        exportCanvaApi('/exports/export-job-1') => $sequence,
        ($downloadUrl ?? exportCanvaDownloadUrl()) => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png'))),
        exportCanvaApi('/oauth/token') => Http::response([
            'access_token' => 'refreshed-access-token',
            'refresh_token' => 'rotated-refresh-token',
            'expires_in' => 14400,
        ]),
    ]);
}

function runExportCanvaDesign(string $importId, string $connectionId, string $workspaceId, string $userId): void
{
    (new ExportCanvaDesign($importId, $connectionId, 'DAF-design-1', $workspaceId, $userId))
        ->handle(app(CanvaClient::class), app(RemoteMediaImporter::class));
}

test('the export job runs on the media-imports queue', function () {
    expect((new ExportCanvaDesign('i', 'c', 'd', 'w', 'u'))->queue)->toBe(ImportRemoteMedia::QUEUE);
});

test('a finished PNG export of page 1 becomes one temporary upload with the design id', function () {
    exportCanvaFake([
        ['status' => 'in_progress'],
        ['status' => 'in_progress'],
        ['status' => 'success', 'urls' => [exportCanvaDownloadUrl()]],
    ]);

    runExportCanvaDesign($this->importId, $this->connection->id, $this->workspace->id, $this->user->id);

    Http::assertSent(fn (Request $request): bool => $request->url() === exportCanvaApi('/exports')
        && $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer canva-access-token')
        && $request->data() === ['design_id' => 'DAF-design-1', 'format' => ['type' => 'png', 'pages' => [1]]]);
    Sleep::assertSleptTimes(2);

    $media = Media::query()->sole();

    expect($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->upload_token)->not->toBeNull()
        ->and($media->mime_type)->toBe('image/png')
        ->and(data_get($media->meta, 'source'))->toBe('canva')
        ->and(data_get($media->meta, 'source_meta'))->toEqual(['design_id' => 'DAF-design-1'])
        ->and(MediaImportStatus::find($this->importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => MediaImportStatus::DONE, 'media_id' => $media->id]);
});

test('an unused Canva upload is pruned after the retention window', function () {
    exportCanvaFake([['status' => 'success', 'urls' => [exportCanvaDownloadUrl()]]]);

    runExportCanvaDesign($this->importId, $this->connection->id, $this->workspace->id, $this->user->id);

    $media = Media::query()->sole();
    Storage::assertExists($media->path);

    $this->travel(23)->hours();
    $this->artisan('media:prune-uploads')->assertSuccessful();
    expect(Media::query()->find($media->id))->not->toBeNull();

    $this->travel(2)->hours();
    $this->artisan('media:prune-uploads')->assertSuccessful();
    expect(Media::query()->find($media->id))->toBeNull();
    Storage::assertMissing($media->path);
});

test('a failed export fails the import', function () {
    exportCanvaFake([['status' => 'failed', 'error' => ['code' => 'license_required', 'message' => 'Premium']]]);

    runExportCanvaDesign($this->importId, $this->connection->id, $this->workspace->id, $this->user->id);

    expect(Media::query()->count())->toBe(0)
        ->and(MediaImportStatus::find($this->importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => MediaImportStatus::FAILED, 'reason' => ExportCanvaDesign::FAILURE]);
});

test('an export still running past the export timeout fails the import', function () {
    config()->set('trypost.media_sources.canva.export_timeout_seconds', 7);
    Sleep::fake(syncWithCarbon: true);
    exportCanvaFake(array_fill(0, 10, ['status' => 'in_progress']));

    runExportCanvaDesign($this->importId, $this->connection->id, $this->workspace->id, $this->user->id);

    expect(Media::query()->count())->toBe(0)
        ->and(MediaImportStatus::find($this->importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => MediaImportStatus::FAILED, 'reason' => ExportCanvaDesign::FAILURE]);
    Sleep::assertSleptTimes(3);
});

test('an export URL outside the Canva download hosts is never fetched', function (string $foreign) {
    config()->set('trypost.media_sources.canva.download_hosts', '*.canva.com');
    exportCanvaFake([['status' => 'success', 'urls' => [$foreign]]], $foreign);

    runExportCanvaDesign($this->importId, $this->connection->id, $this->workspace->id, $this->user->id);

    expect(Media::query()->count())->toBe(0)
        ->and(MediaImportStatus::find($this->importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => MediaImportStatus::FAILED, 'reason' => ImportedFile::HOST_NOT_ALLOWED]);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === $foreign);
})->with([
    'another site' => ['https://evil.example.test/design.png'],
    'suffix lookalike' => ['https://evilcanva.com/design.png'],
    'canva.com as a subdomain' => ['https://canva.com.evil.io/design.png'],
    'export host as a subdomain' => ['https://export-download.canva.com.evil.io/design.png'],
    'bare apex' => ['https://canva.com/design.png'],
]);

test('an export URL on a wildcard Canva host is fetched', function () {
    config()->set('trypost.media_sources.canva.download_hosts', '*.canva.com');
    $url = 'https://export-download.canva.com/abc/design.png';
    exportCanvaFake([['status' => 'success', 'urls' => [$url]]], $url);

    runExportCanvaDesign($this->importId, $this->connection->id, $this->workspace->id, $this->user->id);

    expect(MediaImportStatus::find($this->importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => MediaImportStatus::DONE]);
});

test('an expired access token is refreshed before exporting', function () {
    $this->connection->update(['expires_at' => now()->subMinute(), 'refresh_token' => 'old-refresh-token']);
    exportCanvaFake([['status' => 'success', 'urls' => [exportCanvaDownloadUrl()]]]);

    runExportCanvaDesign($this->importId, $this->connection->id, $this->workspace->id, $this->user->id);

    expect($this->connection->fresh()->refresh_token)->toBe('rotated-refresh-token');
    Http::assertSent(fn (Request $request): bool => $request->url() === exportCanvaApi('/exports')
        && $request->hasHeader('Authorization', 'Bearer refreshed-access-token'));
    expect(MediaImportStatus::find($this->importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => MediaImportStatus::DONE]);
});

test('a refresh another worker already did is reused instead of spending the rotated token', function () {
    $stale = MediaSourceConnection::query()->find($this->connection->id);
    $stale->expires_at = now()->subMinute();
    $this->connection->update(['access_token' => 'token-from-other-worker', 'expires_at' => now()->addHours(4)]);
    exportCanvaFake([]);

    expect(app(CanvaClient::class)->freshAccessToken($stale))->toBe('token-from-other-worker');
    Http::assertNotSent(fn (Request $request): bool => $request->url() === exportCanvaApi('/oauth/token'));
});

test('a revoked refresh token deletes the connection and fails the import', function () {
    $this->connection->update(['expires_at' => now()->subMinute()]);
    Http::fake([
        exportCanvaApi('/oauth/token') => Http::response(['error' => 'invalid_grant'], 400),
    ]);

    runExportCanvaDesign($this->importId, $this->connection->id, $this->workspace->id, $this->user->id);

    expect(MediaSourceConnection::query()->find($this->connection->id))->toBeNull()
        ->and(MediaImportStatus::find($this->importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => MediaImportStatus::FAILED, 'reason' => ExportCanvaDesign::FAILURE]);
    Http::assertNotSent(fn (Request $request): bool => $request->url() === exportCanvaApi('/exports'));
});

test('a connection of another user or a lost workspace access fails without calling Canva', function (string $case) {
    Http::fake();
    $member = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));
    MediaImportStatus::pending($this->importId, $member->id, $this->workspace->id);

    if ($case === 'other user') {
        $connectionId = $this->connection->id;
    } else {
        $connectionId = MediaSourceConnection::factory()->for($member)->create()->id;
        $this->workspace->members()->detach($member->id);
    }

    runExportCanvaDesign($this->importId, $connectionId, $this->workspace->id, $member->id);

    expect(MediaImportStatus::find($this->importId, $member->id, $this->workspace->id))
        ->toMatchArray(['status' => MediaImportStatus::FAILED, 'reason' => ExportCanvaDesign::FAILURE]);
    Http::assertNothingSent();
})->with(['other user', 'lost access']);

test('a crashed export job fails the import', function () {
    (new ExportCanvaDesign($this->importId, $this->connection->id, 'DAF-design-1', $this->workspace->id, $this->user->id))->failed(null);

    expect(MediaImportStatus::find($this->importId, $this->user->id, $this->workspace->id))
        ->toMatchArray(['status' => MediaImportStatus::FAILED, 'reason' => ExportCanvaDesign::FAILURE]);
});
