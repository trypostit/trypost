<?php

declare(strict_types=1);

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\Sources\GooglePhotosPicker;
use App\Support\MediaImportStatus;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

const GOOGLE_PHOTOS_TEST_TOKEN = 'ya29.photos-picker-only-token';

const GOOGLE_PHOTOS_TEST_SESSION = 'a1b2c3d4-e5f6-7890-abcd-ef1234567890';

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
        'trypost.media_sources.google_photos.enabled' => true,
    ]);

    fakePublicDns('142.250.0.10');
});

function googlePhotosApi(): string
{
    return rtrim((string) config('trypost.media_sources.google_photos.api'), '/').'/v1';
}

/**
 * @param  list<array<string, mixed>>  $items
 */
function fakeGooglePhotos(array $items = [], bool $mediaItemsSet = true): void
{
    $api = googlePhotosApi();

    Http::fake([
        "{$api}/sessions/*" => fn (Request $request) => $request->method() === 'DELETE'
            ? Http::response([], 200)
            : Http::response([
                'id' => GOOGLE_PHOTOS_TEST_SESSION,
                'mediaItemsSet' => $mediaItemsSet,
                'pollingConfig' => ['pollInterval' => '4s', 'timeoutIn' => '1200s'],
            ]),
        "{$api}/sessions" => Http::response([
            'id' => GOOGLE_PHOTOS_TEST_SESSION,
            'pickerUri' => 'https://photos.google.com/picker/'.GOOGLE_PHOTOS_TEST_SESSION,
            'pollingConfig' => ['pollInterval' => '3.5s', 'timeoutIn' => '1800s'],
            'mediaItemsSet' => false,
        ]),
        "{$api}/mediaItems*" => Http::response(['mediaItems' => $items]),
        'lh3.googleusercontent.com/photo-one=d' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
        'lh3.googleusercontent.com/video-one=dv' => Http::response(file_get_contents(base_path('tests/fixtures/sample.mp4')), 200, ['Content-Type' => 'video/mp4']),
        '*' => Http::response('unexpected', 500),
    ]);
}

/**
 * @return array<string, mixed>
 */
function googlePhotosItem(string $id, string $type = 'PHOTO', string $baseUrl = 'https://lh3.googleusercontent.com/photo-one', ?string $status = null): array
{
    return [
        'id' => $id,
        'type' => $type,
        'mediaFile' => [
            'baseUrl' => $baseUrl,
            'mimeType' => $type === 'VIDEO' ? 'video/mp4' : 'image/png',
            'filename' => $type === 'VIDEO' ? 'clip.mp4' : 'beach.png',
            'mediaFileMetadata' => $status === null ? [] : ['videoMetadata' => ['processingStatus' => $status]],
        ],
    ];
}

/**
 * What GoogleMediaController's callback leaves behind for a picker session.
 */
function startGooglePhotosSession(User $user, int $expiresIn = 3599): string
{
    GooglePhotosPicker::rememberToken($user->id, GOOGLE_PHOTOS_TEST_SESSION, GOOGLE_PHOTOS_TEST_TOKEN, $expiresIn);

    return GOOGLE_PHOTOS_TEST_SESSION;
}

function importGooglePhotosSession(User $user, string $sessionId = GOOGLE_PHOTOS_TEST_SESSION): string
{
    return test()->actingAs($user)
        ->postJson(route('app.media.imports.store'), ['source' => 'google_photos', 'session_id' => $sessionId])
        ->assertAccepted()
        ->json('import_id');
}

/**
 * @return list<string>
 */
function importGooglePhotosItems(User $user, string $sessionId = GOOGLE_PHOTOS_TEST_SESSION): array
{
    return test()->actingAs($user)
        ->postJson(route('app.media.imports.store'), ['source' => 'google_photos', 'session_id' => $sessionId])
        ->assertAccepted()
        ->json('import_ids');
}

function googlePhotosImportStatus(User $user, string $importId): array
{
    return test()->actingAs($user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->json();
}

test('polling a session reads it on the configured picker api with the stored token', function () {
    fakeGooglePhotos();
    startGooglePhotosSession($this->user);

    $this->actingAs($this->user)
        ->getJson(route('app.media.google-photos.sessions.show', GOOGLE_PHOTOS_TEST_SESSION))
        ->assertOk()
        ->assertExactJson([
            'media_items_set' => true,
            'polling' => ['interval_ms' => 4000, 'timeout_ms' => 1200000],
        ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === googlePhotosApi().'/sessions/'.GOOGLE_PHOTOS_TEST_SESSION
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_PHOTOS_TEST_TOKEN));
});

test('the sessions store route is gone: sessions are only opened by the google sign-in callback', function () {
    expect(Route::has('app.media.google-photos.sessions.store'))->toBeFalse();
});

test('another user cannot poll or import a session', function () {
    Queue::fake();
    fakeGooglePhotos([googlePhotosItem('item-1')]);
    startGooglePhotosSession($this->user);

    $other = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($other->id, membershipPivot('member'));
    $other->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($other)
        ->getJson(route('app.media.google-photos.sessions.show', GOOGLE_PHOTOS_TEST_SESSION))
        ->assertNotFound();

    $this->actingAs($other)
        ->postJson(route('app.media.imports.store'), ['source' => 'google_photos', 'session_id' => GOOGLE_PHOTOS_TEST_SESSION])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('session_id');

    Queue::assertNotPushed(ImportRemoteMedia::class);
});

test('an unknown or malformed session id is rejected', function (string $sessionId) {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => 'google_photos', 'session_id' => $sessionId])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('session_id');

    Queue::assertNotPushed(ImportRemoteMedia::class);
})->with(['unknown-session', '../sessions', 'a/b', '.', '..', '.hidden', '']);

test('the token is cached encrypted for at most an hour', function (int $expiresIn, int $aliveSeconds) {
    fakeGooglePhotos();
    $key = GooglePhotosPicker::cacheKey($this->user->id, GOOGLE_PHOTOS_TEST_SESSION);

    startGooglePhotosSession($this->user, $expiresIn);

    $cached = Cache::get($key);

    expect($cached)->toBeString()
        ->not->toContain(GOOGLE_PHOTOS_TEST_TOKEN)
        ->and(Crypt::decryptString($cached))->toBe(GOOGLE_PHOTOS_TEST_TOKEN);

    $this->travel($aliveSeconds - 1)->seconds();
    expect(Cache::has($key))->toBeTrue();

    $this->travel(2)->seconds();
    expect(Cache::has($key))->toBeFalse();
})->with([
    'short-lived token' => [120, 120],
    'long-lived token' => [7200, 3600],
]);

test('the token is in no response, queued job or database table', function () {
    fakeGooglePhotos([googlePhotosItem('item-1')]);
    config()->set('queue.default', 'database');
    startGooglePhotosSession($this->user);

    $cacheWrites = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$cacheWrites): void {
        $cacheWrites[] = serialize($event->value);
    });

    $session = $this->actingAs($this->user)
        ->getJson(route('app.media.google-photos.sessions.show', GOOGLE_PHOTOS_TEST_SESSION))
        ->assertOk();
    $import = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => 'google_photos', 'session_id' => GOOGLE_PHOTOS_TEST_SESSION])
        ->assertAccepted();

    expect($session->getContent())->not->toContain(GOOGLE_PHOTOS_TEST_TOKEN)
        ->and($import->getContent())->not->toContain(GOOGLE_PHOTOS_TEST_TOKEN)
        ->and(implode("\n", $cacheWrites))->not->toContain(GOOGLE_PHOTOS_TEST_TOKEN)
        ->and(DB::table('jobs')->where('queue', ImportRemoteMedia::QUEUE)->count())->toBe(1);

    $rows = collect(Schema::getTableListing(Schema::getCurrentSchemaListing()))
        ->map(fn (string $table): string => json_encode(DB::table($table)->get(), JSON_INVALID_UTF8_SUBSTITUTE))
        ->implode("\n");

    expect($rows)->not->toContain(GOOGLE_PHOTOS_TEST_TOKEN);

    $job = new ImportRemoteMedia('import-id', $this->workspace->id, $this->user->id, new RemoteFile('https://lh3.googleusercontent.com/photo-one=d', 'beach.png'), GOOGLE_PHOTOS_TEST_SESSION);

    expect(serialize($job))->not->toContain(GOOGLE_PHOTOS_TEST_TOKEN);
});

test('a picked photo is downloaded with =d and the session is deleted and forgotten', function () {
    fakeGooglePhotos([googlePhotosItem('item-1')]);
    startGooglePhotosSession($this->user);

    $importId = importGooglePhotosSession($this->user);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === googlePhotosApi().'/mediaItems?sessionId='.GOOGLE_PHOTOS_TEST_SESSION.'&pageSize=100'
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_PHOTOS_TEST_TOKEN));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://lh3.googleusercontent.com/photo-one=d'
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_PHOTOS_TEST_TOKEN));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === googlePhotosApi().'/sessions/'.GOOGLE_PHOTOS_TEST_SESSION);

    $media = Media::query()->sole();

    expect($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->upload_token)->not->toBeNull()
        ->and($media->original_filename)->toBe('beach.png')
        ->and(data_get($media->meta, 'source'))->toBe(Source::GooglePhotos->value)
        ->and(data_get($media->meta, 'source_meta'))->toEqual(['media_item_id' => 'item-1'])
        ->and(Cache::has(GooglePhotosPicker::cacheKey($this->user->id, GOOGLE_PHOTOS_TEST_SESSION)))->toBeFalse();

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', MediaImportStatus::DONE)
        ->assertJsonPath('media.id', $media->id);

    $this->actingAs($this->user)
        ->getJson(route('app.media.google-photos.sessions.show', GOOGLE_PHOTOS_TEST_SESSION))
        ->assertNotFound();
});

test('a ready video is downloaded with =dv', function () {
    fakeGooglePhotos([googlePhotosItem('video-1', 'VIDEO', 'https://lh3.googleusercontent.com/video-one', 'READY')]);
    startGooglePhotosSession($this->user);

    importGooglePhotosSession($this->user);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://lh3.googleusercontent.com/video-one=dv');

    expect(Media::query()->sole()->original_filename)->toBe('clip.mp4');
});

test('an item that cannot be imported fails the import with a reason and still cleans up', function (array $item, string $reason) {
    fakeGooglePhotos([$item]);
    startGooglePhotosSession($this->user);

    $importId = importGooglePhotosSession($this->user);

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', MediaImportStatus::FAILED)
        ->assertJsonPath('reason', $reason);

    expect(Media::query()->count())->toBe(0)
        ->and(Cache::has(GooglePhotosPicker::cacheKey($this->user->id, GOOGLE_PHOTOS_TEST_SESSION)))->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.test'));
})->with([
    'processing video' => [googlePhotosItem('video-1', 'VIDEO', 'https://lh3.googleusercontent.com/video-one', 'PROCESSING'), GooglePhotosPicker::VIDEO_NOT_READY],
    'video without status' => [googlePhotosItem('video-1', 'VIDEO', 'https://lh3.googleusercontent.com/video-one'), GooglePhotosPicker::VIDEO_NOT_READY],
    'host outside the allowlist' => [googlePhotosItem('item-1', 'PHOTO', 'https://evil.test/photo-one'), 'host_not_allowed'],
    'customer host under googleusercontent' => [googlePhotosItem('item-1', 'PHOTO', 'https://1.2.3.4.bc.googleusercontent.com/photo-one'), 'host_not_allowed'],
]);

test('every picked item becomes its own import, in the order the user picked them', function () {
    fakeGooglePhotos([
        googlePhotosItem('item-1'),
        googlePhotosItem('video-1', 'VIDEO', 'https://lh3.googleusercontent.com/video-one', 'READY'),
    ]);
    startGooglePhotosSession($this->user);

    $importIds = importGooglePhotosItems($this->user);

    expect($importIds)->toHaveCount(2);

    $first = googlePhotosImportStatus($this->user, $importIds[0]);
    $second = googlePhotosImportStatus($this->user, $importIds[1]);

    expect(data_get($first, 'status'))->toBe(MediaImportStatus::DONE)
        ->and(data_get($first, 'media.original_filename'))->toBe('beach.png')
        ->and(data_get($second, 'status'))->toBe(MediaImportStatus::DONE)
        ->and(data_get($second, 'media.original_filename'))->toBe('clip.mp4')
        ->and(Media::query()->count())->toBe(2)
        ->and(Cache::has(GooglePhotosPicker::cacheKey($this->user->id, GOOGLE_PHOTOS_TEST_SESSION)))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://lh3.googleusercontent.com/photo-one=d'
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_PHOTOS_TEST_TOKEN));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://lh3.googleusercontent.com/video-one=dv'
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_PHOTOS_TEST_TOKEN));
    Http::assertSentCount(4);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('an item that cannot be imported fails only its own tile', function () {
    fakeGooglePhotos([
        googlePhotosItem('item-1'),
        googlePhotosItem('video-1', 'VIDEO', 'https://lh3.googleusercontent.com/video-one', 'PROCESSING'),
        googlePhotosItem('item-3', 'PHOTO', 'https://evil.test/photo-three'),
    ]);
    startGooglePhotosSession($this->user);

    $importIds = importGooglePhotosItems($this->user);

    expect($importIds)->toHaveCount(3)
        ->and(data_get(googlePhotosImportStatus($this->user, $importIds[0]), 'status'))->toBe(MediaImportStatus::DONE)
        ->and(googlePhotosImportStatus($this->user, $importIds[1]))->toMatchArray(['status' => MediaImportStatus::FAILED, 'reason' => GooglePhotosPicker::VIDEO_NOT_READY])
        ->and(googlePhotosImportStatus($this->user, $importIds[2]))->toMatchArray(['status' => MediaImportStatus::FAILED, 'reason' => 'host_not_allowed'])
        ->and(Media::query()->sole()->original_filename)->toBe('beach.png')
        ->and(Cache::has(GooglePhotosPicker::cacheKey($this->user->id, GOOGLE_PHOTOS_TEST_SESSION)))->toBeFalse();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('the token and the session outlive the first finished item until the last one is done', function () {
    Queue::fake();
    fakeGooglePhotos([googlePhotosItem('item-1'), googlePhotosItem('video-1', 'VIDEO', 'https://lh3.googleusercontent.com/video-one', 'READY')]);
    startGooglePhotosSession($this->user);
    $key = GooglePhotosPicker::cacheKey($this->user->id, GOOGLE_PHOTOS_TEST_SESSION);

    importGooglePhotosItems($this->user);

    $jobs = Queue::pushed(ImportRemoteMedia::class)->values();

    expect($jobs)->toHaveCount(2)
        ->and($jobs->pluck('googlePhotosSession')->unique()->all())->toBe([GOOGLE_PHOTOS_TEST_SESSION]);

    $jobs[0]->handle(app(RemoteMediaImporter::class));

    expect(Cache::has($key))->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    $jobs[1]->handle(app(RemoteMediaImporter::class));

    expect(Cache::has($key))->toBeFalse()
        ->and(Media::query()->count())->toBe(2);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('every page of picked items is read, up to the configured maximum', function () {
    Queue::fake();
    config()->set('trypost.media_sources.google_photos.max_items', 3);
    $api = googlePhotosApi();

    Http::fake([
        "{$api}/mediaItems*" => fn (Request $request) => data_get($request->data(), 'pageToken') === 'page-2'
            ? Http::response(['mediaItems' => [googlePhotosItem('item-3'), googlePhotosItem('item-4')]])
            : Http::response(['mediaItems' => [googlePhotosItem('item-1'), googlePhotosItem('item-2')], 'nextPageToken' => 'page-2']),
        '*' => Http::response('unexpected', 500),
    ]);
    startGooglePhotosSession($this->user);

    expect(importGooglePhotosItems($this->user))->toHaveCount(3);

    expect(Queue::pushed(ImportRemoteMedia::class)->map(fn (ImportRemoteMedia $job): string => data_get($job->file->sourceMeta, 'media_item_id'))->values()->all())
        ->toBe(['item-1', 'item-2', 'item-3']);
    Http::assertSent(fn (Request $request): bool => data_get($request->data(), 'pageToken') === 'page-2');
});

test('a session that cannot be read starts one failed import and is discarded', function () {
    Queue::fake();
    fakeGooglePhotos([]);
    startGooglePhotosSession($this->user);

    $importIds = importGooglePhotosItems($this->user);

    expect($importIds)->toHaveCount(1)
        ->and(googlePhotosImportStatus($this->user, $importIds[0]))->toMatchArray(['status' => MediaImportStatus::FAILED, 'reason' => GooglePhotosPicker::SESSION_EXPIRED])
        ->and(Cache::has(GooglePhotosPicker::cacheKey($this->user->id, GOOGLE_PHOTOS_TEST_SESSION)))->toBeFalse();

    Queue::assertNotPushed(ImportRemoteMedia::class);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('a session whose token expired before the job ran fails as expired', function () {
    Queue::fake();
    fakeGooglePhotos([googlePhotosItem('item-1')]);
    startGooglePhotosSession($this->user);
    $importId = importGooglePhotosSession($this->user);

    GooglePhotosPicker::forget($this->user->id, GOOGLE_PHOTOS_TEST_SESSION);

    Queue::assertPushed(ImportRemoteMedia::class, function (ImportRemoteMedia $job): bool {
        $job->handle(app()->make(RemoteMediaImporter::class));

        return true;
    });

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertJsonPath('status', MediaImportStatus::FAILED)
        ->assertJsonPath('reason', GooglePhotosPicker::SESSION_EXPIRED);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'googleusercontent.com'));
});

test('google photos is hidden and its routes are missing when the flag is off, even with every google media key', function () {
    Queue::fake();
    fakeGooglePhotos();
    config()->set('trypost.media_sources.google_photos.enabled', false);
    GooglePhotosPicker::rememberToken($this->user->id, GOOGLE_PHOTOS_TEST_SESSION, GOOGLE_PHOTOS_TEST_TOKEN, 3599);

    expect(collect(Source::menu())->pluck('source'))->not->toContain('google_photos');

    $this->actingAs($this->user)
        ->getJson(route('app.integrations.google.start', ['source' => 'google_photos', 'nonce' => 'composer-nonce-0123456789']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');

    $this->actingAs($this->user)
        ->getJson(route('app.media.google-photos.sessions.show', GOOGLE_PHOTOS_TEST_SESSION))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => 'google_photos', 'session_id' => GOOGLE_PHOTOS_TEST_SESSION])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');

    Http::assertNothingSent();
    Queue::assertNotPushed(ImportRemoteMedia::class);
});

test('the shipped config leaves google photos off and allows only the photos download hosts', function () {
    $keys = ['GOOGLE_PHOTOS_ENABLED', 'GOOGLE_PHOTOS_DOWNLOAD_HOSTS'];
    $saved = collect($keys)->mapWithKeys(fn (string $key): array => [$key => [getenv($key), $_ENV[$key] ?? null, $_SERVER[$key] ?? null]]);

    foreach ($keys as $key) {
        putenv($key);
        unset($_ENV[$key], $_SERVER[$key]);
    }

    try {
        $config = require config_path('trypost.php');
    } finally {
        foreach ($saved as $key => [$env, $envArray, $server]) {
            if ($env !== false) {
                putenv("{$key}={$env}");
            }
            if ($envArray !== null) {
                $_ENV[$key] = $envArray;
            }
            if ($server !== null) {
                $_SERVER[$key] = $server;
            }
        }
    }

    expect(data_get($config, 'media_sources.google_photos.enabled'))->toBeFalse()
        ->and(data_get($config, 'media_sources.google_photos.download_hosts'))
        ->toBe('lh3.googleusercontent.com,lh4.googleusercontent.com,lh5.googleusercontent.com,lh6.googleusercontent.com,video-downloads.googleusercontent.com');
});

test('a user outside the workspace cannot open, poll or discard a session', function (string $method, string $route) {
    fakeGooglePhotos();
    $outsider = workspaceOutsider($this->workspace);
    GooglePhotosPicker::rememberToken($outsider->id, GOOGLE_PHOTOS_TEST_SESSION, GOOGLE_PHOTOS_TEST_TOKEN, 3599);

    $this->actingAs($outsider->fresh())
        ->json($method, $route === 'start'
            ? route('app.integrations.google.start', ['source' => 'google_photos', 'nonce' => 'composer-nonce-0123456789'])
            : route("app.media.google-photos.sessions.{$route}", GOOGLE_PHOTOS_TEST_SESSION))
        ->assertForbidden();

    Http::assertNothingSent();
})->with([
    'start' => ['GET', 'start'],
    'show' => ['GET', 'show'],
    'destroy' => ['DELETE', 'destroy'],
]);

test('a cancelled session is deleted at google and forgotten, and only by its owner', function () {
    fakeGooglePhotos();
    startGooglePhotosSession($this->user);
    $key = GooglePhotosPicker::cacheKey($this->user->id, GOOGLE_PHOTOS_TEST_SESSION);

    $other = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($other->id, membershipPivot('member'));
    $other->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($other)
        ->deleteJson(route('app.media.google-photos.sessions.destroy', GOOGLE_PHOTOS_TEST_SESSION))
        ->assertNotFound();

    expect(Cache::has($key))->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    $this->actingAs($this->user)
        ->deleteJson(route('app.media.google-photos.sessions.destroy', GOOGLE_PHOTOS_TEST_SESSION))
        ->assertNoContent();

    expect(Cache::has($key))->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === googlePhotosApi().'/sessions/'.GOOGLE_PHOTOS_TEST_SESSION
        && $request->hasHeader('Authorization', 'Bearer '.GOOGLE_PHOTOS_TEST_TOKEN));
});

test('polling a session is throttled per user', function () {
    fakeGooglePhotos(mediaItemsSet: false);
    config()->set('trypost.media_sources.google_photos.polls_per_user_per_minute', 2);
    startGooglePhotosSession($this->user);

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($this->user)
            ->getJson(route('app.media.google-photos.sessions.show', GOOGLE_PHOTOS_TEST_SESSION))
            ->assertOk();
    }

    $this->actingAs($this->user)
        ->getJson(route('app.media.google-photos.sessions.show', GOOGLE_PHOTOS_TEST_SESSION))
        ->assertTooManyRequests();
});

test('a job killed by its timeout still releases its item, and a double release counts once', function () {
    Queue::fake();
    fakeGooglePhotos([googlePhotosItem('item-1'), googlePhotosItem('video-1', 'VIDEO', 'https://lh3.googleusercontent.com/video-one', 'READY')]);
    startGooglePhotosSession($this->user);
    $key = GooglePhotosPicker::cacheKey($this->user->id, GOOGLE_PHOTOS_TEST_SESSION);

    importGooglePhotosItems($this->user);
    $jobs = Queue::pushed(ImportRemoteMedia::class)->values();

    $jobs[0]->failed(new RuntimeException('timed out'));
    $jobs[0]->failed(new RuntimeException('timed out'));

    expect(Cache::has($key))->toBeTrue();
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE');

    $jobs[1]->failed(new RuntimeException('timed out'));

    expect(Cache::has($key))->toBeFalse();
    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
});

test('reading picked items stops at an empty page and never pages past the item cap', function (array $page, int $requests, int $imports) {
    Queue::fake();
    config()->set('trypost.media_sources.google_photos.max_items', 3);
    $api = googlePhotosApi();

    Http::fake([
        "{$api}/mediaItems*" => Http::response($page),
        "{$api}/sessions/*" => Http::response([]),
        '*' => Http::response('unexpected', 500),
    ]);
    startGooglePhotosSession($this->user);

    expect(importGooglePhotosItems($this->user))->toHaveCount($imports);

    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/mediaItems')))->toHaveCount($requests);
})->with([
    'empty page with a next token' => [['mediaItems' => [], 'nextPageToken' => 'more'], 1, 1],
    'one item per page, endless tokens' => [['mediaItems' => [googlePhotosItem('item-1')], 'nextPageToken' => 'more'], 2, 2],
]);
