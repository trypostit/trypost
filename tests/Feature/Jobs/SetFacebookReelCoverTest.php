<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Http\Resources\App\PostCardResource;
use App\Jobs\SetFacebookReelCover;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Social\FacebookCover;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

const FACEBOOK_COVER_URL = 'https://cdn.test/medias/cover.png';

beforeEach(function () {
    Queue::fake();

    $this->account = SocialAccount::factory()->facebook()->create([
        'platform_user_id' => 'page_123',
        'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_read_user_content', 'pages_manage_engagement'],
    ]);
    $this->post = Post::factory()->forAccount($this->account, ContentType::FacebookReel)->published()->create([
        'platform_post_id' => 'video_123',
        'media' => [[
            'id' => 'video-1',
            'path' => 'medias/video.mp4',
            'url' => 'https://cdn.test/medias/video.mp4',
            'type' => 'video',
            'mime_type' => 'video/mp4',
            'meta' => ['cover_source' => 'image', 'cover' => [
                'id' => 'cover-1',
                'path' => 'medias/cover.png',
                'url' => FACEBOOK_COVER_URL,
                'type' => 'image',
                'mime_type' => 'image/png',
            ]],
        ]],
    ]);
});

function facebookThumbnailsUrl(): string
{
    return config('trypost.platforms.facebook.graph_api').'/video_123/thumbnails';
}

function facebookReelStatusUrl(): string
{
    return config('trypost.platforms.facebook.graph_api').'/video_123?fields=status*';
}

function facebookReelStatus(string $videoStatus): array
{
    return ['status' => ['video_status' => $videoStatus]];
}

function facebookCoverPng(): string
{
    return (string) (new ImageManager(Driver::class))->createImage(180, 320)->fill('336699')->encodeUsingMediaType('image/png');
}

function runSetFacebookReelCover(Post $post): SetFacebookReelCover
{
    $job = (new SetFacebookReelCover($post))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

test('a published reel gets its cover image as the preferred thumbnail', function () {
    Http::fake([
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['success' => true]),
    ]);

    runSetFacebookReelCover($this->post)->assertNotReleased();

    Http::assertSent(function (Request $request): bool {
        if ($request->url() !== facebookThumbnailsUrl()) {
            return false;
        }

        $parts = collect($request->data())->keyBy('name');

        return $request->isMultipart()
            && $request->hasFile('source')
            && str_starts_with((string) data_get($parts, 'source.contents'), "\xFF\xD8")
            && data_get($parts, 'is_preferred.contents') === 'true';
    });
    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeFalse();
});

test('a cover facebook refuses leaves a warning and the reel published', function () {
    Http::fake([
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['error' => ['message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => 100]], 400),
    ]);

    runSetFacebookReelCover($this->post)->assertNotReleased();

    $post = $this->post->fresh();
    expect(FacebookCover::wasRefused($post->error_context))->toBeTrue()
        ->and($post->status->value)->toBe('published')
        ->and(PostCardResource::make($post)->resolve()['facebook_cover_warning'])->toBeTrue();
});

test('a channel without the reel cover scopes leaves a warning without asking facebook', function () {
    Http::fake();
    $this->account->update(['scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts']]);

    runSetFacebookReelCover($this->post);

    Http::assertNothingSent();
    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeTrue();
});

test('a channel whose granted scopes are unknown still gets its reel cover', function (?array $scopes) {
    Http::fake([
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['success' => true]),
    ]);
    $this->account->update(['scopes' => $scopes]);

    runSetFacebookReelCover($this->post)->assertNotReleased();

    Http::assertSent(fn (Request $request): bool => $request->url() === facebookThumbnailsUrl());
    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeFalse();
})->with(['null' => [null], 'empty' => [[]]]);

test('a reel without a cover asks facebook nothing', function () {
    Http::fake();
    $this->post->update(['media' => [[...$this->post->media[0], 'meta' => []]]]);

    runSetFacebookReelCover($this->post);

    Http::assertNothingSent();
    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeFalse();
});

test('a facebook server error tries the cover again', function () {
    Http::fake([
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'type' => 'OAuthException', 'code' => 2, 'is_transient' => true]], 500),
    ]);

    runSetFacebookReelCover($this->post)->assertReleased(SetFacebookReelCover::CHECK_EVERY_SECONDS);

    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeFalse();
});

test('a failure a later try may fix tries the cover again without a warning', function (Closure $fakes) {
    Http::fake($fakes());

    runSetFacebookReelCover($this->post)->assertReleased(SetFacebookReelCover::CHECK_EVERY_SECONDS);

    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeFalse();
})->with([
    'a gateway error on the cover' => fn () => fn (): array => [
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response('<html>Bad gateway</html>', 502),
    ],
    'too many requests on the cover' => fn () => fn (): array => [
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response('', 429),
    ],
    'a page rate limit on the cover' => fn () => fn (): array => [
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['error' => ['message' => 'Limit', 'type' => 'OAuthException', 'code' => 80001]], 400),
    ],
    'facebook unreachable' => fn () => fn (): array => [
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::failedConnection(),
    ],
    'the cover not downloading' => fn () => fn (): array => [
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response('', 404),
    ],
    'the status read failing for now' => fn () => fn (): array => [
        facebookReelStatusUrl() => Http::response('', 503),
    ],
]);

test('a channel that is no longer connected leaves a warning without asking facebook', function () {
    Http::fake();
    $this->account->update(['status' => AccountStatus::TokenExpired]);

    runSetFacebookReelCover($this->post)->assertNotReleased();

    Http::assertNothingSent();
    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeTrue();
});

test('a cover that never went on records a warning when the job gives up', function () {
    (new SetFacebookReelCover($this->post))->failed(null);

    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeTrue();
});

test('a cover that cannot be made a jpeg leaves a warning at once instead of trying again', function () {
    Http::fake([
        facebookReelStatusUrl() => Http::response(facebookReelStatus('ready')),
        FACEBOOK_COVER_URL => Http::response('not an image', 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['success' => true]),
    ]);

    runSetFacebookReelCover($this->post)->assertNotReleased();

    Http::assertNotSent(fn (Request $request): bool => $request->url() === facebookThumbnailsUrl());
    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeTrue();
});

test('a reel facebook is still processing is tried again before its cover is sent', function (string $videoStatus) {
    Http::fake([
        facebookReelStatusUrl() => Http::response(facebookReelStatus($videoStatus)),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['success' => true]),
    ]);

    runSetFacebookReelCover($this->post)->assertReleased(SetFacebookReelCover::CHECK_EVERY_SECONDS);

    Http::assertNotSent(fn (Request $request): bool => $request->url() === facebookThumbnailsUrl());
    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeFalse();
})->with(['processing', 'upload_complete']);

test('a reel facebook could not process leaves a warning without sending its cover', function (string $videoStatus) {
    Http::fake([
        facebookReelStatusUrl() => Http::response(facebookReelStatus($videoStatus)),
        FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
        facebookThumbnailsUrl() => Http::response(['success' => true]),
    ]);

    runSetFacebookReelCover($this->post)->assertNotReleased();

    Http::assertNotSent(fn (Request $request): bool => $request->url() === facebookThumbnailsUrl());
    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeTrue();
})->with(['error', 'expired']);

test('a reel facebook cannot load leaves a warning, since it may be a lost permission and not a deletion', function () {
    Http::fake([
        facebookReelStatusUrl() => Http::response(['error' => ['message' => 'Unsupported get request. Object with ID video_123 does not exist, cannot be loaded due to missing permissions', 'type' => 'GraphMethodException', 'code' => 100, 'error_subcode' => 33]], 400),
    ]);

    runSetFacebookReelCover($this->post)->assertNotReleased();

    Http::assertNotSent(fn (Request $request): bool => $request->url() === facebookThumbnailsUrl());
    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeTrue();
});

test('a page token that expired leaves a warning at once instead of trying again', function () {
    Http::fake([
        facebookReelStatusUrl() => Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]], 400),
    ]);

    runSetFacebookReelCover($this->post)->assertNotReleased();

    expect(FacebookCover::wasRefused($this->post->fresh()->error_context))->toBeTrue();
});

test('a post deleted while its cover job retried lets the job fail quietly', function () {
    Post::query()->whereKey($this->post->id)->delete();

    (new SetFacebookReelCover($this->post))->failed(null);

    expect(Post::find($this->post->id))->toBeNull();
});

test('a try outlasts its three slow requests and stays under the queue retry window', function () {
    $timeout = (new SetFacebookReelCover($this->post))->timeout;

    expect($timeout)->toBeGreaterThan(3 * 120)
        ->and($timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
});
