<?php

declare(strict_types=1);

use App\Enums\Media\Source;
use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Models\Account;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\UnsplashService;
use App\Support\HeicConverter;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Storage::fake();
    fakePublicDns();

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
});

function mediaUploadChunked(User $user, string $content, string $fileName): TestResponse
{
    $size = strlen($content);

    return test()->actingAs($user)->call(
        'POST',
        route('app.media.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'bytes 0-'.($size - 1)."/{$size}",
            'HTTP_X_FILE_NAME' => $fileName,
            'HTTP_X_UPLOAD_ID' => Str::uuid()->toString(),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        $content,
    );
}

test('a chunked upload completes as a temporary upload with an upload token', function (string $fixture, string $fileName, MediaType $type) {
    $response = mediaUploadChunked($this->user, file_get_contents(base_path("tests/fixtures/{$fixture}")), $fileName)
        ->assertOk()
        ->assertJson(['done' => true, 'type' => $type->value]);

    $media = Media::query()->sole();

    expect(Str::isUuid($response->json('upload_token')))->toBeTrue()
        ->and($response->json('upload_token'))->toBe($media->upload_token)
        ->and($response->json('id'))->toBe($media->id)
        ->and($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->post_id)->toBeNull()
        ->and($media->type)->toBe($type)
        ->and(Storage::exists($media->path))->toBeTrue();
})->with([
    'png' => ['1x1.png', 'photo.png', MediaType::Image],
    'mp4' => ['sample.mp4', 'clip.mp4', MediaType::Video],
]);

test('the uploaded tile is adopted by the post save that follows', function () {
    $channel = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $tile = Arr::except(
        mediaUploadChunked($this->user, file_get_contents(base_path('tests/fixtures/1x1.png')), 'photo.png')->assertOk()->json(),
        ['done'],
    );

    test()->actingAs($this->user)->post(route('app.posts.store'), [
        'status' => 'draft',
        'content' => 'With an upload',
        'media' => [$tile],
        'destinations' => [[
            'social_account_id' => $channel->id,
            'content_type' => ContentType::LinkedInPost->value,
        ]],
    ])->assertSessionHasNoErrors();

    $post = Post::query()->sole();

    expect(Media::query()->sole())
        ->id->toBe(data_get($tile, 'id'))
        ->post_id->toBe($post->id)
        ->collection->toBe(Media::COLLECTION_MEDIA)
        ->upload_token->toBeNull()
        ->and(data_get($post->media, '0.id'))->toBe(data_get($tile, 'id'));
});

test('a chunked pdf completes as a temporary upload', function () {
    $response = mediaUploadChunked($this->user, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n", 'deck.pdf')
        ->assertOk()
        ->assertJson(['done' => true, 'type' => MediaType::Document->value]);

    expect(Media::query()->sole())
        ->collection->toBe(Media::COLLECTION_UPLOADS)
        ->upload_token->toBe($response->json('upload_token'));
});

test('an intermediate chunk reports progress and creates nothing', function () {
    test()->actingAs($this->user)->call(
        'POST',
        route('app.media.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'bytes 0-499/1000',
            'HTTP_X_FILE_NAME' => 'clip.mp4',
            'HTTP_X_UPLOAD_ID' => Str::uuid()->toString(),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        str_repeat('a', 500),
    )->assertOk()->assertExactJson(['done' => false, 'progress' => 50]);

    expect(Media::query()->count())->toBe(0);
});

test('a chunked upload with an unsupported extension is rejected', function () {
    mediaUploadChunked($this->user, str_repeat('x', 100), 'malware.exe')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file_name');

    expect(Media::query()->count())->toBe(0);
});

test('a user outside the workspace cannot upload', function () {
    $outsider = workspaceOutsider($this->workspace);

    mediaUploadChunked($outsider->fresh(), file_get_contents(base_path('tests/fixtures/1x1.png')), 'photo.png')
        ->assertForbidden();

    test()->actingAs($outsider->fresh())
        ->postJson(route('app.media.store-from-url'), [
            'url' => 'https://images.unsplash.com/photo-test',
            'filename' => 'unsplash-test.jpg',
        ])
        ->assertForbidden();

    test()->actingAs($outsider->fresh())
        ->getJson(route('app.media.unsplash.search', ['query' => 'nature']))
        ->assertForbidden();

    expect(Media::query()->count())->toBe(0);
});

test('a chunked upload with an invalid content-range header is rejected', function () {
    test()->actingAs($this->user)->call(
        'POST',
        route('app.media.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => 'invalid',
            'HTTP_X_FILE_NAME' => 'test.jpg',
            'HTTP_X_UPLOAD_ID' => Str::uuid()->toString(),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        'data',
    )
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['range_start', 'range_end', 'total_size']);

    expect(Media::query()->count())->toBe(0);
});

test('a guest cannot upload', function () {
    test()->call('POST', route('app.media.store-chunked'), [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'x')
        ->assertUnauthorized();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function unsplashPick(array $overrides = []): array
{
    $images = Source::Unsplash->downloadHosts()[0];
    $website = config('trypost.media_sources.unsplash.website');

    return [
        'url' => "https://{$images}/photo-test",
        'filename' => 'unsplash-test.jpg',
        'download_location' => config('trypost.media_sources.unsplash.api').'/photos/test/download',
        'photo_id' => 'test',
        'author_name' => 'Ana Photographer',
        'author_url' => "{$website}/@ana?utm_source=trypost&utm_medium=referral",
        ...$overrides,
    ];
}

function unsplashImagesPattern(): string
{
    return Source::Unsplash->downloadHosts()[0].'/*';
}

test('an unsplash photo imports through the importer as a temporary upload and sends the tracking hit', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    $fake = UploadedFile::fake()->image('photo.jpg', 800, 600);
    $image = file_get_contents($fake->getPathname());
    $download = config('trypost.media_sources.unsplash.api').'/photos/test/download';
    Http::fake([
        unsplashImagesPattern() => Http::response($image, 200, ['Content-Type' => 'image/jpeg']),
        $download => Http::response(['url' => 'https://example.test']),
    ]);

    $response = test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick())
        ->assertCreated()
        ->assertJsonPath('type', MediaType::Image->value);

    $media = Media::query()->sole();

    expect($response->json('upload_token'))->toBe($media->upload_token)
        ->and(Str::isUuid($media->upload_token))->toBeTrue()
        ->and($media->collection)->toBe(Media::COLLECTION_UPLOADS)
        ->and($media->workspace_id)->toBe($this->workspace->id)
        ->and($media->post_id)->toBeNull()
        ->and($media->original_filename)->toBe('unsplash-test.jpg')
        ->and(data_get($media->meta, 'width'))->toBe(800)
        ->and(data_get($media->meta, 'height'))->toBe(600)
        ->and(data_get($media->meta, 'source'))->toBe(Source::Unsplash->value)
        ->and(data_get($media->meta, 'source_meta'))->toEqual([
            'photo_id' => 'test',
            'author_name' => 'Ana Photographer',
            'author_url' => config('trypost.media_sources.unsplash.website').'/@ana?utm_source=trypost&utm_medium=referral',
        ])
        ->and($response->json('meta.source_meta.author_name'))->toBe('Ana Photographer')
        ->and(Storage::get($media->path))->toBe($image);

    Http::assertSent(fn (Request $request) => $request->url() === $download
        && $request->hasHeader('Authorization', 'Client-ID unsplash-key'));
});

test('a url outside the configured download hosts is rejected before any request', function (string $url) {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    Http::fake();

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick(['url' => $url]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    Http::assertNothingSent();
    expect(Media::query()->count())->toBe(0);
})->with([
    'private ip' => ['http://127.0.0.1/evil.jpg'],
    'giphy' => ['https://media0.giphy.com/media/abc/giphy.gif'],
    'look-alike host' => ['https://images.unsplash.com.evil.test/photo.jpg'],
    'plain http' => ['http://images.unsplash.com/photo-test'],
]);

test('the configured download hosts decide what is accepted', function () {
    config()->set([
        'services.unsplash.access_key' => 'unsplash-key',
        'trypost.media_sources.unsplash.download_hosts' => 'cdn.unsplash.example.test',
    ]);
    Http::fake();

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick(['url' => 'https://images.unsplash.com/photo-test']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('url');

    Http::assertNothingSent();
});

test('a download location on another host is rejected before any request', function (string $downloadLocation) {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    Http::fake();

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick(['download_location' => $downloadLocation]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('download_location');

    Http::assertNothingSent();
    expect(Media::query()->count())->toBe(0);
})->with([
    'another host' => ['https://evil.test/photos/test/download'],
    'internal address' => ['https://169.254.169.254/latest/meta-data'],
    'the image host' => ['https://images.unsplash.com/photos/test/download'],
]);

test('the attribution is required and the author link stays on the unsplash website', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    Http::fake();

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick(['author_url' => 'https://evil.test/@ana', 'author_name' => '', 'photo_id' => null]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['author_url', 'author_name', 'photo_id']);

    Http::assertNothingSent();
});

test('unsplash picks are refused while unsplash is not configured', function () {
    config()->set('services.unsplash.access_key', null);
    Http::fake();

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url', 'download_location']);

    Http::assertNothingSent();
});

test('the media library and giphy routes are gone while the upload routes remain', function () {
    foreach ([
        'app.assets.index',
        'app.assets.search',
        'app.assets.store',
        'app.assets.store-chunked',
        'app.assets.store-from-url',
        'app.assets.download',
        'app.assets.destroy',
        'app.assets.giphy.search',
        'app.assets.giphy.trending',
    ] as $removed) {
        expect(Route::has($removed))->toBeFalse();
    }

    expect(Route::has('app.media.store-chunked'))->toBeTrue()
        ->and(Route::has('app.media.store-from-url'))->toBeTrue()
        ->and(Route::has('app.media.unsplash.search'))->toBeTrue();
});

test('an allowed host that redirects is refused and nothing is tracked', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    Http::fake([
        unsplashImagesPattern() => Http::response('', 302, ['Location' => 'http://127.0.0.1/internal']),
        'http://127.0.0.1/*' => Http::response('internal secret', 200),
    ]);
    $this->mock(UnsplashService::class)->shouldReceive('trackDownload')->never();

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url' => __('posts.composer.media_sources.errors.import_failed')]);

    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '127.0.0.1'));
    expect(Media::query()->count())->toBe(0);
});

test('unsplash search and trending are served under media', function () {
    $unsplash = $this->mock(UnsplashService::class);
    $unsplash->shouldReceive('search')->with('nature', 1)->once()->andReturn(['results' => [['id' => 'abc']], 'total' => 1, 'total_pages' => 1]);
    $unsplash->shouldReceive('trending')->with(2)->once()->andReturn([['id' => 'xyz']]);

    test()->actingAs($this->user)
        ->getJson(route('app.media.unsplash.search', ['query' => 'nature']))
        ->assertOk()
        ->assertJsonPath('total', 1);

    test()->actingAs($this->user)
        ->getJson(route('app.media.unsplash.trending', ['page' => 2]))
        ->assertOk()
        ->assertJsonPath('results.0.id', 'xyz');
});

test('the shared upload limits carry the three byte caps and the upload retention', function () {
    config()->set([
        'trypost.media.max_size_mb.image' => 7,
        'trypost.media.max_size_mb.video' => 300,
        'trypost.media.max_size_mb.document' => 40,
        'trypost.media.upload_retention_hours' => 12,
    ]);

    $limits = test()->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->viewData('page')['props']['mediaUploadLimits'];

    expect($limits)->toBe([
        'max_bytes' => [
            'image' => 7 * 1024 * 1024,
            'video' => 300 * 1024 * 1024,
            'document' => 40 * 1024 * 1024,
        ],
        'extensions' => [
            'image' => MediaType::Image->extensions(),
            'video' => MediaType::Video->extensions(),
            'document' => MediaType::Document->extensions(),
        ],
        'upload_retention_hours' => 12,
        'heic' => HeicConverter::available(),
    ]);
});

test('the shared upload limits are null for a guest', function () {
    test()->get(route('login'))->assertInertia(fn ($page) => $page->where('mediaUploadLimits', null));
});

function mediaUploadDeclare(User $user, string $fileName, int $totalSize): TestResponse
{
    return test()->actingAs($user)->call(
        'POST',
        route('app.media.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => "bytes 0-99/{$totalSize}",
            'HTTP_X_FILE_NAME' => $fileName,
            'HTTP_X_UPLOAD_ID' => Str::uuid()->toString(),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        str_repeat('a', 100),
    );
}

function mediaUploadPaddedPng(int $size): string
{
    $png = file_get_contents(base_path('tests/fixtures/1x1.png'));

    return $png.str_repeat("\0", $size - strlen($png));
}

test('the chunked endpoint enforces exactly the cap the shared prop advertises for each type', function (MediaType $type, string $fileName) {
    config()->set([
        'trypost.media.max_size_mb.image' => 1,
        'trypost.media.max_size_mb.video' => 2,
        'trypost.media.max_size_mb.document' => 3,
    ]);

    $advertised = test()->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->viewData('page')['props']['mediaUploadLimits']['max_bytes'][$type->value];

    expect($advertised)->toBe($type->maxSizeInBytes());

    mediaUploadDeclare($this->user, $fileName, $advertised)->assertOk()->assertJson(['done' => false]);
    mediaUploadDeclare($this->user, $fileName, $advertised + 1)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['total_size' => __('posts.composer.upload_errors.too_large', ['size' => $type->maxSizeInMb()])]);
})->with([
    'image' => [MediaType::Image, 'photo.png'],
    'video' => [MediaType::Video, 'clip.mp4'],
    'document' => [MediaType::Document, 'deck.pdf'],
]);

test('an assembled file over the cap of the type its bytes are is rejected and deleted', function (string $fileName, string $declaredRange) {
    config()->set(['trypost.media.max_size_mb.image' => 1, 'trypost.media.max_size_mb.video' => 2]);
    $bytes = mediaUploadPaddedPng(MediaType::Image->maxSizeInBytes() + 1);

    test()->actingAs($this->user)->call(
        'POST',
        route('app.media.store-chunked'),
        [], [], [],
        [
            'HTTP_CONTENT_RANGE' => $declaredRange === 'full' ? 'bytes 0-'.(strlen($bytes) - 1).'/'.strlen($bytes) : 'bytes 0-99/100',
            'HTTP_X_FILE_NAME' => $fileName,
            'HTTP_X_UPLOAD_ID' => Str::uuid()->toString(),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/octet-stream',
        ],
        $bytes,
    )->assertUnprocessable()->assertJsonValidationErrors(['total_size' => __('posts.composer.upload_errors.too_large', ['size' => 1])]);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
})->with([
    'image bytes named as a video' => ['clip.mp4', 'full'],
    'more bytes than the declared size' => ['photo.png', 'small'],
]);

test('a user outside the workspace gets 403 before validation runs', function () {
    $outsider = workspaceOutsider($this->workspace);

    test()->actingAs($outsider->fresh())
        ->call('POST', route('app.media.store-chunked'), [], [], [], ['HTTP_CONTENT_RANGE' => 'invalid', 'HTTP_ACCEPT' => 'application/json'], 'x')
        ->assertForbidden();

    test()->actingAs($outsider->fresh())
        ->postJson(route('app.media.store-from-url'), ['url' => 'not a url'])
        ->assertForbidden();
});

test('a from-url body that is not an image fails the import whatever its content type says', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    Http::fake([unsplashImagesPattern() => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'image/png'])]);
    $this->mock(UnsplashService::class)->shouldReceive('trackDownload')->never();

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url' => __('posts.composer.media_sources.errors.import_failed')]);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
});

test('a gif body labelled jpeg is stored as a gif', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    Http::fake([
        unsplashImagesPattern() => Http::response($gif, 200, ['Content-Type' => 'image/jpeg']),
        config('trypost.media_sources.unsplash.api').'/*' => Http::response([]),
    ]);

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick())
        ->assertCreated()
        ->assertJsonPath('mime_type', 'image/gif');

    expect(Media::query()->sole())
        ->mime_type->toBe('image/gif')
        ->type->toBe(MediaType::Image);
});

test('an unsplash photo over the image cap is rejected and leaves no row or file', function () {
    config()->set(['services.unsplash.access_key' => 'unsplash-key', 'trypost.media.max_size_mb.image' => 1]);
    Http::fake([
        unsplashImagesPattern() => Http::response(mediaUploadPaddedPng(MediaType::Image->maxSizeInBytes() + 1), 200, ['Content-Type' => 'image/png']),
    ]);
    $this->mock(UnsplashService::class)->shouldReceive('trackDownload')->never();

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['url' => __('posts.composer.media_sources.errors.import_failed')]);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::allFiles())->toBe([]);
});

test('a failed tracking hit does not undo the pick', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    Http::fake([
        unsplashImagesPattern() => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
        config('trypost.media_sources.unsplash.api').'/*' => Http::failedConnection(),
    ]);

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick())
        ->assertCreated();

    expect(Media::query()->count())->toBe(1);
});

test('unsplash results carry referral links for the photographer and unsplash', function () {
    config()->set([
        'services.unsplash.access_key' => 'unsplash-key',
        'trypost.media_sources.unsplash.website' => 'https://unsplash.example.test',
    ]);
    Http::fake([config('trypost.media_sources.unsplash.api').'/photos*' => Http::response([[
        'id' => 'abc',
        'urls' => ['small' => 's', 'regular' => 'r', 'full' => 'f'],
        'links' => ['download_location' => 'd'],
        'user' => ['name' => 'Ana', 'links' => ['html' => 'https://unsplash.example.test/@ana']],
    ]])]);

    test()->actingAs($this->user)
        ->getJson(route('app.media.unsplash.trending'))
        ->assertOk()
        ->assertJsonPath('results.0.author.url', 'https://unsplash.example.test/@ana?utm_source=trypost&utm_medium=referral')
        ->assertJsonPath('results.0.unsplash_url', 'https://unsplash.example.test/?utm_source=trypost&utm_medium=referral');
});

test('a photo without a photographer link keeps a null author link', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    Http::fake([config('trypost.media_sources.unsplash.api').'/photos*' => Http::response([[
        'id' => 'abc',
        'urls' => ['small' => 's', 'regular' => 'r', 'full' => 'f'],
        'links' => ['download_location' => 'd'],
        'user' => ['name' => 'Ana'],
    ]])]);

    test()->actingAs($this->user)
        ->getJson(route('app.media.unsplash.trending'))
        ->assertOk()
        ->assertJsonPath('results.0.author.url', null);

    Http::fake([
        unsplashImagesPattern() => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
        config('trypost.media_sources.unsplash.api').'/*' => Http::response([]),
    ]);

    test()->actingAs($this->user)
        ->postJson(route('app.media.store-from-url'), unsplashPick(['author_url' => null]))
        ->assertCreated()
        ->assertJsonPath('meta.source_meta.author_url', null);
});

test('trending pages are cached and shared while failures are not', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    $api = config('trypost.media_sources.unsplash.api');
    Http::fakeSequence("{$api}/photos*")
        ->push(['errors' => ['Rate Limit Exceeded']], 403)
        ->push([['id' => 'abc', 'user' => ['name' => 'Ana']]]);

    test()->actingAs($this->user)
        ->getJson(route('app.media.unsplash.trending'))
        ->assertServiceUnavailable()
        ->assertJsonPath('message', __('posts.composer.unsplash.error'));

    test()->actingAs($this->user)->getJson(route('app.media.unsplash.trending'))->assertOk()->assertJsonPath('results.0.id', 'abc');
    test()->actingAs($this->user)->getJson(route('app.media.unsplash.trending'))->assertOk()->assertJsonPath('results.0.id', 'abc');

    Http::assertSentCount(2);
});

test('an unsplash search failure is an error, not an empty result, and searches every orientation', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    $api = config('trypost.media_sources.unsplash.api');
    Http::fakeSequence("{$api}/search/photos*")
        ->pushFailedConnection()
        ->push(['results' => [], 'total' => 0, 'total_pages' => 0]);

    test()->actingAs($this->user)
        ->getJson(route('app.media.unsplash.search', ['query' => 'cats']))
        ->assertServiceUnavailable();

    test()->actingAs($this->user)
        ->getJson(route('app.media.unsplash.search', ['query' => 'cats']))
        ->assertOk()
        ->assertJsonPath('total', 0);

    Http::assertSent(fn (Request $request) => str_contains($request->url(), '/search/photos')
        && data_get($request->data(), 'query') === 'cats'
        && ! array_key_exists('orientation', $request->data()));
});

test('unsplash browsing is throttled per user', function () {
    config()->set(['services.unsplash.access_key' => 'unsplash-key', 'trypost.media_sources.unsplash.requests_per_user_per_minute' => 2]);
    Http::fake([config('trypost.media_sources.unsplash.api').'/*' => Http::response([])]);

    test()->actingAs($this->user)->getJson(route('app.media.unsplash.trending'))->assertOk();
    test()->actingAs($this->user)->getJson(route('app.media.unsplash.search', ['query' => 'cats']))->assertOk();
    test()->actingAs($this->user)->getJson(route('app.media.unsplash.trending', ['page' => 2]))->assertTooManyRequests();
});

test('unsplash picks share the media imports throttle', function () {
    config()->set(['services.unsplash.access_key' => 'unsplash-key', 'trypost.media_sources.imports_per_user_per_minute' => 1]);
    Http::fake([
        unsplashImagesPattern() => Http::response(file_get_contents(base_path('tests/fixtures/1x1.png')), 200, ['Content-Type' => 'image/png']),
        config('trypost.media_sources.unsplash.api').'/*' => Http::response([]),
    ]);

    test()->actingAs($this->user)->postJson(route('app.media.store-from-url'), unsplashPick())->assertCreated();
    test()->actingAs($this->user)->postJson(route('app.media.store-from-url'), unsplashPick())->assertTooManyRequests();

    expect(Media::query()->count())->toBe(1);
});

test('unsplash search and trending reject pages past the cap', function (string $routeName, array $query) {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    Http::fake();

    test()->actingAs($this->user)
        ->getJson(route($routeName, [...$query, 'page' => 101]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('page');

    Http::assertNothingSent();
})->with([
    'search' => ['app.media.unsplash.search', ['query' => 'cats']],
    'trending' => ['app.media.unsplash.trending', []],
]);

test('unsplash results are cached per query, page and page size', function () {
    config()->set('services.unsplash.access_key', 'unsplash-key');
    $api = config('trypost.media_sources.unsplash.api');
    Http::fake(["{$api}/search/photos*" => Http::response(['results' => [], 'total' => 0, 'total_pages' => 0])]);

    $search = fn (array $params) => test()->actingAs($this->user)
        ->getJson(route('app.media.unsplash.search', $params))
        ->assertOk();

    $search(['query' => 'cats']);
    $search(['query' => 'cats']);
    $search(['query' => 'dogs']);
    $search(['query' => 'cats', 'page' => 2]);

    config()->set('app.pagination.default', (int) config('app.pagination.default') + 1);
    $search(['query' => 'cats']);

    Http::assertSentCount(4);
});
