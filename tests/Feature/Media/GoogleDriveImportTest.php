<?php

declare(strict_types=1);

use App\Actions\Media\StartMediaImport;
use App\Enums\Media\Source;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Providers\TelescopeServiceProvider;
use App\Services\Http\HostResolver;
use App\Services\Media\Sources\GoogleDriveFiles;
use App\Support\MediaImportHeaders;
use App\Support\MediaImportStatus;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Laravel\Telescope\ExtractProperties;
use Laravel\Telescope\Telescope;

const GOOGLE_DRIVE_TEST_TOKEN = 'ya29.drive-file-only-token';

beforeEach(function () {
    Storage::fake();

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
        'services.google_media.client_id' => 'media-client-id',
        'services.google_media.client_secret' => 'media-client-secret',
        'services.google_media.redirect' => 'https://trypost.example/integrations/google/callback',
        'services.google_media.api_key' => 'media-api-key',
        'services.google_media.app_id' => '123456789012',
        'trypost.media_sources.google_drive.enabled' => true,
    ]);

    $this->mock(HostResolver::class)
        ->shouldReceive('addresses')
        ->andReturn(['142.250.0.10']);
});

function googleDriveImportPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'source' => 'google_drive',
        'access_token' => GOOGLE_DRIVE_TEST_TOKEN,
        'file' => ['id' => '1AbC_dE-fGh', 'name' => 'beach.png'],
    ], $overrides);
}

function fakeGoogleDriveDownload(): string
{
    $api = config('trypost.media_sources.google_drive.api');

    Http::fake([
        "{$api}/files/*" => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
    ]);

    return $api;
}

test('a file id that is not a plain drive id is rejected', function (string $fileId) {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), googleDriveImportPayload(['file' => ['id' => $fileId]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file.id');

    Queue::assertNotPushed(ImportRemoteMedia::class);
})->with(['a/b', '..', '../abc', 'abc?alt=json', 'abc def', 'abc%2F', '']);

test('a files array is rejected', function () {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), googleDriveImportPayload([
            'files' => [['id' => 'a', 'name' => 'a.png'], ['id' => 'b', 'name' => 'b.png']],
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('files');

    Queue::assertNotPushed(ImportRemoteMedia::class);
});

test('the token and file are required and bounded', function (array $payload, string $error) {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    Queue::assertNotPushed(ImportRemoteMedia::class);
})->with([
    'no token' => [['source' => 'google_drive', 'file' => ['id' => 'abc', 'name' => 'a.png']], 'access_token'],
    'token too long' => [['source' => 'google_drive', 'access_token' => str_repeat('a', 4097), 'file' => ['id' => 'abc', 'name' => 'a.png']], 'access_token'],
    'no file' => [['source' => 'google_drive', 'access_token' => 'token'], 'file'],
    'extra file keys' => [['source' => 'google_drive', 'access_token' => 'token', 'file' => ['id' => 'abc', 'name' => 'a.png', 'url' => 'https://evil.test']], 'file'],
    'name too long' => [['source' => 'google_drive', 'access_token' => 'token', 'file' => ['id' => 'abc', 'name' => str_repeat('a', 256)]], 'file.name'],
]);

test('a picked file is downloaded with the bearer token from the configured drive host into a temporary upload', function () {
    $api = fakeGoogleDriveDownload();

    $importId = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), googleDriveImportPayload())
        ->assertAccepted()
        ->json('import_id');

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === "{$api}/files/1AbC_dE-fGh?alt=media&supportsAllDrives=true"
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_DRIVE_TEST_TOKEN));

    $media = Media::query()->sole();

    expect($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->upload_token)->not->toBeNull()
        ->and($media->original_filename)->toBe('beach.png')
        ->and(data_get($media->meta, 'source'))->toBe(Source::GoogleDrive->value)
        ->and(data_get($media->meta, 'source_meta'))->toEqual(['file_id' => '1AbC_dE-fGh']);

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', MediaImportStatus::DONE)
        ->assertJsonPath('media.id', $media->id);
});

function googleDriveTableRows(): string
{
    return collect(Schema::getTableListing(Schema::getCurrentSchemaListing()))
        ->map(fn (string $table): string => json_encode(DB::table($table)->get(), JSON_INVALID_UTF8_SUBSTITUTE))
        ->implode("\n");
}

test('the access token stays out of the response, the queued job, every table and the cache once used', function () {
    $api = fakeGoogleDriveDownload();
    config()->set('queue.default', 'database');

    $cacheWrites = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$cacheWrites): void {
        $cacheWrites[] = serialize($event->value);
    });

    $response = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), googleDriveImportPayload())
        ->assertAccepted();
    $importId = $response->json('import_id');

    $stashed = Cache::get(MediaImportHeaders::key($importId));
    $payload = (string) DB::table('jobs')->where('queue', ImportRemoteMedia::QUEUE)->sole()->payload;
    $job = unserialize(Crypt::decrypt(data_get(json_decode($payload, true), 'data.command')));

    expect($response->getContent())->not->toContain(GOOGLE_DRIVE_TEST_TOKEN)
        ->and(implode("\n", $cacheWrites))->not->toContain(GOOGLE_DRIVE_TEST_TOKEN)
        ->and($stashed)->toBeString()
        ->and(Crypt::decryptString($stashed))->toContain(GOOGLE_DRIVE_TEST_TOKEN)
        ->and($payload)->not->toContain(GOOGLE_DRIVE_TEST_TOKEN)
        ->and($payload)->not->toContain($stashed)
        ->and($job)->toBeInstanceOf(ImportRemoteMedia::class)
        ->and($job->file->headers)->toBe([])
        ->and(serialize($job))->not->toContain(GOOGLE_DRIVE_TEST_TOKEN)
        ->and(googleDriveTableRows())->not->toContain(GOOGLE_DRIVE_TEST_TOKEN);

    app()->call([$job, 'handle']);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), "{$api}/files/1AbC_dE-fGh")
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_DRIVE_TEST_TOKEN));

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))->toMatchArray(['status' => MediaImportStatus::DONE])
        ->and(Cache::has(MediaImportHeaders::key($importId)))->toBeFalse()
        ->and(implode("\n", $cacheWrites))->not->toContain(GOOGLE_DRIVE_TEST_TOKEN)
        ->and(googleDriveTableRows())->not->toContain(GOOGLE_DRIVE_TEST_TOKEN);
});

test('the stashed token is dropped when the import fails', function () {
    $api = config('trypost.media_sources.google_drive.api');
    Http::fake(["{$api}/files/*" => Http::response('', 500)]);

    $importId = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), googleDriveImportPayload())
        ->assertAccepted()
        ->json('import_id');

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))->toMatchArray(['status' => MediaImportStatus::FAILED])
        ->and(Cache::has(MediaImportHeaders::key($importId)))->toBeFalse();
});

test('the stashed token is dropped when the job fails or the user lost access', function () {
    Queue::fake();
    $file = GoogleDriveFiles::toRemoteFile(GOOGLE_DRIVE_TEST_TOKEN, ['id' => 'abc', 'name' => 'a.png']);

    [$failed] = StartMediaImport::execute($this->user, $this->workspace, Source::GoogleDrive, $file);
    (new ImportRemoteMedia($failed, $this->workspace->id, $this->user->id, $file->withHeaders([])))->failed(new RuntimeException('worker died'));

    [$revoked] = StartMediaImport::execute($this->user, $this->workspace, Source::GoogleDrive, $file);
    $this->workspace->members()->detach($this->user->id);
    app()->call([new ImportRemoteMedia($revoked, $this->workspace->id, $this->user->id, $file->withHeaders([])), 'handle']);

    expect(Cache::has(MediaImportHeaders::key($failed)))->toBeFalse()
        ->and(Cache::has(MediaImportHeaders::key($revoked)))->toBeFalse();
    Http::assertNothingSent();
});

test('the bearer token is not forwarded on a redirect to googleusercontent', function () {
    $api = config('trypost.media_sources.google_drive.api');

    Http::fake([
        "{$api}/files/*" => Http::response('', 302, ['Location' => 'https://doc-0s-1a-docs.googleusercontent.com/docs/securesc/x']),
        'https://doc-0s-1a-docs.googleusercontent.com/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
    ]);

    $importId = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), googleDriveImportPayload())
        ->assertAccepted()
        ->json('import_id');

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))->toMatchArray(['status' => MediaImportStatus::DONE]);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), $api)
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_DRIVE_TEST_TOKEN));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'googleusercontent.com')
        && ! $request->hasHeader('Authorization'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleusercontent.com')
        && $request->hasHeader('Authorization'));
});

test('telescope never records the access token', function () {
    $job = new ImportRemoteMedia('import-id', $this->workspace->id, $this->user->id, GoogleDriveFiles::toRemoteFile(GOOGLE_DRIVE_TEST_TOKEN, ['id' => 'abc', 'name' => 'a.png']));

    expect(json_encode(ExtractProperties::from($job)))->not->toContain(GOOGLE_DRIVE_TEST_TOKEN)
        ->and(data_get(ExtractProperties::from($job), 'file.properties.headers.Authorization'))->toBe('********');

    $hiddenParameters = Telescope::$hiddenRequestParameters;
    $hiddenHeaders = Telescope::$hiddenRequestHeaders;
    $filters = Telescope::$filterUsing;

    try {
        (new TelescopeServiceProvider(app()))->register();

        expect(Telescope::$hiddenRequestParameters)->toContain('access_token');
    } finally {
        Telescope::$hiddenRequestParameters = $hiddenParameters;
        Telescope::$hiddenRequestHeaders = $hiddenHeaders;
        Telescope::$filterUsing = $filters;
    }
});

test('google drive is not importable or offered when its flag is off, even with the keys set', function () {
    Queue::fake();
    config()->set('trypost.media_sources.google_drive.enabled', false);

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), googleDriveImportPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');

    expect(collect(Source::menu())->pluck('source'))->not->toContain('google_drive');
    Queue::assertNotPushed(ImportRemoteMedia::class);
});

test('google drive is not importable or offered with only the login google client', function () {
    Queue::fake();
    config()->set([
        'services.google.client_id' => 'login-client-id',
        'services.google-auth.client_id' => 'login-client-id',
        'services.google_media.client_id' => null,
        'services.google_media.api_key' => null,
        'services.google_media.app_id' => null,
    ]);

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), googleDriveImportPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');

    expect(collect(Source::menu())->pluck('source'))->not->toContain('google_drive');
    Queue::assertNotPushed(ImportRemoteMedia::class);
});

test('the drive url is built only from the configured host and a plain id', function () {
    config()->set('trypost.media_sources.google_drive.api', 'https://drive.example.test/drive/v3/');

    $file = GoogleDriveFiles::toRemoteFile('token', ['id' => 'abc-1_2', 'name' => 'a.png']);

    expect($file->url)->toBe('https://drive.example.test/drive/v3/files/abc-1_2?alt=media&supportsAllDrives=true')
        ->and($file->headers)->toBe(['Authorization' => 'Bearer token'])
        ->and($file->allowedHosts)->toBe(Source::GoogleDrive->downloadHosts())
        ->and($file->source)->toBe(Source::GoogleDrive)
        ->and($file->sourceMeta)->toBe(['file_id' => 'abc-1_2']);

    expect(fn () => GoogleDriveFiles::toRemoteFile('token', ['id' => '../abc', 'name' => 'a.png']))
        ->toThrow(InvalidArgumentException::class);
});
