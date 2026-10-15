<?php

declare(strict_types=1);

use App\Enums\Media\Source;
use App\Jobs\Media\ImportRemoteMedia;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Http\HostResolver;
use App\Services\Media\Sources\WebdavFiles;
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

const WEBDAV_TEST_URL = 'https://cloud.example.com/remote.php/dav/files/media-bot';
const WEBDAV_TEST_PASSWORD = 'app-password-Xy7-secret';

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
        'trypost.media_sources.webdav.enabled' => true,
        'trypost.media_sources.webdav.url' => WEBDAV_TEST_URL,
        'trypost.media_sources.webdav.username' => 'media-bot',
        'trypost.media_sources.webdav.password' => WEBDAV_TEST_PASSWORD,
        'trypost.media_sources.webdav.root' => 'Shared Media',
        'trypost.media_sources.webdav.label' => 'Nextcloud',
    ]);

    $this->mock(HostResolver::class)
        ->shouldReceive('addresses')
        ->andReturn(['93.184.216.34']);
});

/**
 * A Depth: 1 answer as Nextcloud sends it: the folder itself first, hrefs
 * URL-encoded and absolute.
 *
 * @param  list<array{0: string, 1: ?string, 2?: int}>  $children  [href below the root folder, content type or null for a folder, size]
 */
function webdavMultistatus(string $folder, array $children): string
{
    $encode = fn (string $path): string => implode('/', array_map('rawurlencode', explode('/', $path)));
    $base = '/remote.php/dav/files/media-bot/Shared%20Media/'.($folder === '' ? '' : $encode($folder).'/');
    $response = fn (string $href, ?string $type, int $size = 0): string => '<d:response><d:href>'.$href.'</d:href><d:propstat><d:prop>'
        .($type === null
            ? '<d:resourcetype><d:collection/></d:resourcetype>'
            : '<d:resourcetype/><d:getcontenttype>'.$type.'</d:getcontenttype><d:getcontentlength>'.$size.'</d:getcontentlength><d:getlastmodified>Tue, 13 Oct 2026 08:00:00 GMT</d:getlastmodified>')
        .'</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response>';

    return '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
        .$response($base, null)
        .implode('', array_map(fn (array $child): string => $response($base.$encode($child[0]), $child[1], $child[2] ?? 0), $children))
        .'</d:multistatus>';
}

function webdavTableRows(): string
{
    return collect(Schema::getTableListing(Schema::getCurrentSchemaListing()))
        ->map(fn (string $table): string => json_encode(DB::table($table)->get(), JSON_INVALID_UTF8_SUBSTITUTE))
        ->implode("\n");
}

// ------------------------------------------------------------- availability ---

test('webdav is offered under its configured label without exposing the share', function () {
    $option = collect(Source::menu())->firstWhere('source', 'webdav');

    expect($option)->toBe(['source' => 'webdav', 'label' => 'Nextcloud', 'config' => []])
        ->and(json_encode(Source::menu()))->not->toContain(WEBDAV_TEST_PASSWORD)
        ->and(json_encode(Source::menu()))->not->toContain('cloud.example.com');
});

test('webdav is neither offered, browsable nor importable while disabled or incomplete', function (array $config) {
    Queue::fake();
    config()->set($config);

    expect(collect(Source::menu())->pluck('source'))->not->toContain('webdav');

    $this->actingAs($this->user)
        ->getJson(route('app.media.webdav.browse'))
        ->assertNotFound();

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => 'webdav', 'file' => ['path' => 'a.jpg']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');

    Queue::assertNotPushed(ImportRemoteMedia::class);
})->with([
    'flag off' => [['trypost.media_sources.webdav.enabled' => false]],
    'no url' => [['trypost.media_sources.webdav.url' => null]],
    'no username' => [['trypost.media_sources.webdav.username' => '']],
    'no password' => [['trypost.media_sources.webdav.password' => null]],
]);

test('only the configured share host may serve downloads', function () {
    expect(Source::Webdav->downloadHosts())->toBe(['cloud.example.com']);
});

// ----------------------------------------------------------------- browsing ---

test('a folder lists sub-folders first, then images and videos, and hides everything else', function () {
    Http::fake([
        WEBDAV_TEST_URL.'/*' => Http::response(webdavMultistatus('Fotos', [
            ['zebra.jpg', 'image/jpeg', 2048],
            ['Fotobox/', null],
            ['clip.mp4', 'video/mp4', 4096],
            ['notes.pdf', 'application/pdf', 10],
            ['.hidden.png', 'image/png', 1],
            ['Archiv/', null],
            ['no-type.heic', 'application/octet-stream', 5],
        ]), 207),
    ]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.webdav.browse', ['path' => 'Fotos']))
        ->assertOk()
        ->assertJsonPath('path', 'Fotos')
        ->assertJsonPath('entries.*.name', ['Archiv', 'Fotobox', 'clip.mp4', 'no-type.heic', 'zebra.jpg'])
        ->assertJsonPath('entries.0', ['type' => 'folder', 'name' => 'Archiv', 'path' => 'Fotos/Archiv'])
        ->assertJsonPath('entries.4.mime', 'image/jpeg')
        ->assertJsonPath('entries.4.size', 2048)
        ->assertJsonPath('entries.4.path', 'Fotos/zebra.jpg')
        ->assertJsonPath('entries.4.modified', '2026-10-13T08:00:00+00:00');
});

test('the listing is requested with basic auth from the configured root, encoded segment by segment', function () {
    Http::fake([WEBDAV_TEST_URL.'/*' => Http::response(webdavMultistatus('BB 2026/Kamera & Co', []), 207)]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.webdav.browse', ['path' => 'BB 2026/Kamera & Co']))
        ->assertOk()
        ->assertJsonPath('entries', []);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PROPFIND'
        && $request->url() === WEBDAV_TEST_URL.'/Shared%20Media/BB%202026/Kamera%20%26%20Co/'
        && $request->hasHeader('Depth', '1')
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('media-bot:'.WEBDAV_TEST_PASSWORD)));
});

test('a path that tries to leave the root is refused before anything is requested', function (string $path) {
    Http::fake();

    $this->actingAs($this->user)
        ->getJson(route('app.media.webdav.browse', ['path' => $path]))
        ->assertUnprocessable();

    Http::assertNothingSent();
})->with(['..', '../other-user', 'Fotos/../../etc', './Fotos', 'Fotos//x', "Fo\0tos", 'Fotos\\..\\x']);

test('an unreachable or failing share answers with a plain message and leaks nothing', function (mixed $answer) {
    Http::fake([WEBDAV_TEST_URL.'/*' => $answer]);

    $response = $this->actingAs($this->user)
        ->getJson(route('app.media.webdav.browse'))
        ->assertStatus(502)
        ->assertJsonPath('message', __('posts.composer.media_sources.webdav.load_failed'));

    expect($response->getContent())->not->toContain('cloud.example.com')
        ->and($response->getContent())->not->toContain(WEBDAV_TEST_PASSWORD);
})->with([
    'server error' => [fn () => Http::response('', 500)],
    'wrong password' => [fn () => Http::response('', 401)],
    'redirect' => [fn () => Http::response('', 302, ['Location' => 'https://elsewhere.example/'])],
    'not xml' => [fn () => Http::response('<html>login</html>', 207)],
]);

test('entries outside the root folder are ignored even if the server returns them', function () {
    $xml = str_replace(
        '</d:multistatus>',
        '<d:response><d:href>/remote.php/dav/files/media-bot/Private/secret.jpg</d:href><d:propstat><d:prop><d:resourcetype/><d:getcontenttype>image/jpeg</d:getcontenttype></d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>',
        webdavMultistatus('', [['ok.jpg', 'image/jpeg', 1]]),
    );
    Http::fake([WEBDAV_TEST_URL.'/*' => Http::response($xml, 207)]);

    $this->actingAs($this->user)
        ->getJson(route('app.media.webdav.browse'))
        ->assertOk()
        ->assertJsonPath('entries.*.name', ['ok.jpg']);
});

test('browsing needs a signed-in user', function () {
    Http::fake();

    $this->getJson(route('app.media.webdav.browse'))
        ->assertUnauthorized();

    Http::assertNothingSent();
});

// ---------------------------------------------------------------- importing ---

test('a picked file is downloaded from the share with the credentials into a temporary upload', function () {
    Http::fake([WEBDAV_TEST_URL.'/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png'])]);

    $importId = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => 'webdav', 'file' => ['path' => 'BB 2026/beach.png']])
        ->assertAccepted()
        ->json('import_id');

    Http::assertSent(fn (Request $request): bool => $request->url() === WEBDAV_TEST_URL.'/Shared%20Media/BB%202026/beach.png'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('media-bot:'.WEBDAV_TEST_PASSWORD)));

    $media = Media::query()->sole();

    expect($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->original_filename)->toBe('beach.png')
        ->and(data_get($media->meta, 'source'))->toBe('webdav')
        ->and(data_get($media->meta, 'source_meta'))->toEqual(['path' => 'BB 2026/beach.png']);

    $this->actingAs($this->user)
        ->getJson(route('app.media.imports.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', MediaImportStatus::DONE);
});

test('the share password stays out of the response, the queued job, every table and the cache', function () {
    Http::fake([WEBDAV_TEST_URL.'/*' => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png'])]);
    config()->set('queue.default', 'database');

    $encoded = base64_encode('media-bot:'.WEBDAV_TEST_PASSWORD);
    $cacheWrites = [];
    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$cacheWrites): void {
        $cacheWrites[] = serialize($event->value);
    });

    $response = $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => 'webdav', 'file' => ['path' => 'beach.png']])
        ->assertAccepted();
    $importId = $response->json('import_id');

    $payload = (string) DB::table('jobs')->where('queue', ImportRemoteMedia::QUEUE)->sole()->payload;
    $job = unserialize(Crypt::decrypt(data_get(json_decode($payload, true), 'data.command')));

    foreach ([WEBDAV_TEST_PASSWORD, $encoded] as $secret) {
        expect($response->getContent())->not->toContain($secret)
            ->and(implode("\n", $cacheWrites))->not->toContain($secret)
            ->and($payload)->not->toContain($secret)
            ->and(serialize($job))->not->toContain($secret)
            ->and(webdavTableRows())->not->toContain($secret);
    }

    app()->call([$job, 'handle']);

    expect(MediaImportStatus::find($importId, $this->user->id, $this->workspace->id))->toMatchArray(['status' => MediaImportStatus::DONE])
        ->and(Cache::has(MediaImportHeaders::key($importId)))->toBeFalse()
        ->and(webdavTableRows())->not->toContain(WEBDAV_TEST_PASSWORD);
});

test('an import path that tries to leave the root is rejected', function (string $path) {
    Queue::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.media.imports.store'), ['source' => 'webdav', 'file' => ['path' => $path]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file.path');

    Queue::assertNotPushed(ImportRemoteMedia::class);
})->with(['', '/', '..', '../x.jpg', 'a/../../x.jpg', 'a//b.jpg']);

test('the download is never sent anywhere but the configured share', function () {
    $file = WebdavFiles::toRemoteFile('a.jpg');

    expect($file->url)->toStartWith(WEBDAV_TEST_URL.'/')
        ->and($file->allowedHosts)->toBe(['cloud.example.com'])
        ->and($file->source)->toBe(Source::Webdav);
});
