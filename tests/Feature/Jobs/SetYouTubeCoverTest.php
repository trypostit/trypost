<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Enums\YouTube\ThumbnailWarning;
use App\Exceptions\TokenExpiredException;
use App\Http\Resources\App\PostCardResource;
use App\Jobs\SetYouTubeCover;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use Google\Client;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

const YOUTUBE_VIDEOS_URL = 'https://youtube.googleapis.com/youtube/v3/videos*';
const YOUTUBE_THUMBNAILS_URL = 'https://youtube.googleapis.com/upload/youtube/v3/thumbnails/set*';
const YOUTUBE_COVER_URL = 'https://cdn.test/medias/cover.jpg';

beforeEach(function () {
    Queue::fake();

    $this->account = SocialAccount::factory()->youtube()->create(['token_expires_at' => now()->addDay()]);
    $this->post = Post::factory()->forAccount($this->account, ContentType::YouTubeShort)->published()->create([
        'platform_post_id' => 'short-id',
        'media' => [[
            'id' => 'video-1',
            'path' => 'medias/video.mp4',
            'url' => 'https://cdn.test/medias/video.mp4',
            'type' => 'video',
            'mime_type' => 'video/mp4',
            'meta' => ['cover' => [
                'id' => 'cover-1',
                'path' => 'medias/cover.jpg',
                'url' => YOUTUBE_COVER_URL,
                'type' => 'image',
                'mime_type' => 'image/jpeg',
            ]],
        ]],
    ]);
});

function youTubeProcessing(string $status): array
{
    return ['items' => [['id' => 'short-id', 'processingDetails' => ['processingStatus' => $status]]]];
}

/**
 * The Google client is built after `Http::fake()`, so its requests reach the fakes.
 */
function runSetYouTubeCover(Post $post): SetYouTubeCover
{
    $client = new Client;
    $client->setHttpClient(Http::buildClient());
    app()->instance(Client::class, $client);

    $job = (new SetYouTubeCover($post))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

test('a processed video gets the cover image as its thumbnail', function () {
    Http::fake([
        YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing('succeeded')),
        YOUTUBE_COVER_URL => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
        YOUTUBE_THUMBNAILS_URL => Http::response(['kind' => 'youtube#thumbnailSetResponse', 'items' => []]),
    ]);

    runSetYouTubeCover($this->post)->assertNotReleased();

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/thumbnails/set')
        && str_contains($request->url(), 'videoId=short-id')
        && $request->body() === 'jpeg bytes');
    expect($this->post->fresh()->youTubeThumbnailWarning())->toBeNull();
});

test('a video still processing is checked again a minute later', function () {
    Http::fake([YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing('processing'))]);

    runSetYouTubeCover($this->post)->assertReleased(delay: SetYouTubeCover::CHECK_EVERY_SECONDS);

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/thumbnails/set'));
});

test('a cover YouTube refuses leaves a warning and the post published', function (int $status, ThumbnailWarning $warning) {
    Http::fake([
        YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing('succeeded')),
        YOUTUBE_COVER_URL => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
        YOUTUBE_THUMBNAILS_URL => Http::response(['error' => ['code' => $status, 'message' => 'refused', 'errors' => [['reason' => 'refused']]]], $status),
    ]);

    runSetYouTubeCover($this->post)->assertNotReleased();

    expect($this->post->fresh()->youTubeThumbnailWarning())->toBe($warning)
        ->and($this->post->fresh()->publish_status)->toBe(PublishStatus::Published);
})->with([
    'channel not verified' => [403, ThumbnailWarning::Forbidden],
    'daily limit' => [429, ThumbnailWarning::DailyLimit],
    'invalid image' => [400, ThumbnailWarning::InvalidImage],
]);

test('a server error is tried again', function () {
    Http::fake([
        YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing('succeeded')),
        YOUTUBE_COVER_URL => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
        YOUTUBE_THUMBNAILS_URL => Http::response(['error' => ['code' => 503, 'message' => 'backend']], 503),
    ]);

    runSetYouTubeCover($this->post)->assertReleased(delay: SetYouTubeCover::CHECK_EVERY_SECONDS);

    expect($this->post->fresh()->youTubeThumbnailWarning())->toBeNull();
});

test('running out of tries leaves the failed warning', function () {
    (new SetYouTubeCover($this->post))->failed(null);

    expect($this->post->fresh()->youTubeThumbnailWarning())->toBe(ThumbnailWarning::Failed);
});

test('a post without a cover image asks youtube nothing', function () {
    $this->post->update(['media' => [[...$this->post->media[0], 'meta' => []]]]);
    Http::fake();

    runSetYouTubeCover($this->post);

    Http::assertNothingSent();
});

test('the post card shows the cover warning without a failure', function () {
    $this->post->recordYouTubeThumbnailWarning(ThumbnailWarning::Forbidden);

    $card = PostCardResource::make($this->post->fresh())->resolve();

    expect($card['youtube_thumbnail_warning'])->toBe('forbidden')
        ->and($card['failure'])->toBeNull();
});

test('a video youtube could not process leaves the failed warning', function (string $status) {
    Http::fake([YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing($status))]);

    runSetYouTubeCover($this->post)->assertNotReleased();

    expect($this->post->fresh()->youTubeThumbnailWarning())->toBe(ThumbnailWarning::Failed);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/thumbnails/set'));
})->with(['failed', 'terminated']);

test('a video youtube no longer lists leaves no warning', function () {
    Http::fake([YOUTUBE_VIDEOS_URL => Http::response(['items' => []])]);

    runSetYouTubeCover($this->post)->assertNotReleased();

    expect($this->post->fresh()->youTubeThumbnailWarning())->toBeNull();
});

test('a revoked token leaves the failed warning at once instead of retrying', function () {
    $this->account->update(['token_expires_at' => now()->subMinute()]);
    $verifier = Mockery::mock(ConnectionVerifier::class);
    $verifier->shouldReceive('refreshToken')->once()->andThrow(new TokenExpiredException('revoked'));
    $this->app->instance(ConnectionVerifier::class, $verifier);
    Http::fake();

    runSetYouTubeCover($this->post)->assertNotReleased();

    expect($this->post->fresh()->youTubeThumbnailWarning())->toBe(ThumbnailWarning::Failed);
});

test('a cover that is not a jpeg or a png reaches youtube as a jpeg', function () {
    $this->post->update(['media' => [[...$this->post->media[0], 'meta' => ['cover' => [
        ...data_get($this->post->media, '0.meta.cover'),
        'mime_type' => 'image/webp',
    ]]]]]);
    $webp = (string) (new ImageManager(Driver::class))->createImage(320, 568)->fill('336699')->encodeUsingMediaType('image/webp');
    Http::fake([
        YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing('succeeded')),
        YOUTUBE_COVER_URL => Http::response($webp, 200, ['Content-Type' => 'image/webp']),
        YOUTUBE_THUMBNAILS_URL => Http::response(['kind' => 'youtube#thumbnailSetResponse', 'items' => []]),
    ]);

    runSetYouTubeCover($this->post);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/thumbnails/set')
        && $request->header('Content-Type')[0] === 'image/jpeg'
        && str_starts_with($request->body(), "\xFF\xD8"));
});

test('a cover that cannot be made a jpeg leaves the invalid image warning at once', function () {
    $this->post->update(['media' => [[...$this->post->media[0], 'meta' => ['cover' => [
        ...data_get($this->post->media, '0.meta.cover'),
        'mime_type' => 'image/webp',
    ]]]]]);
    Http::fake([
        YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing('succeeded')),
        YOUTUBE_COVER_URL => Http::response('not an image', 200, ['Content-Type' => 'image/webp']),
    ]);

    runSetYouTubeCover($this->post)->assertNotReleased();

    expect($this->post->fresh()->youTubeThumbnailWarning())->toBe(ThumbnailWarning::InvalidImage);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/thumbnails/set'));
});

test('a video removed before its thumbnail was set leaves no warning', function () {
    Http::fake([
        YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing('succeeded')),
        YOUTUBE_COVER_URL => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
        YOUTUBE_THUMBNAILS_URL => Http::response(['error' => ['code' => 404, 'message' => 'not found', 'errors' => [['reason' => 'videoNotFound']]]], 404),
    ]);

    runSetYouTubeCover($this->post)->assertNotReleased();

    expect($this->post->fresh()->youTubeThumbnailWarning())->toBeNull();
});

test('a channel no longer connected leaves the failed warning', function () {
    $this->account->update(['status' => AccountStatus::TokenExpired]);
    Http::fake();

    runSetYouTubeCover($this->post);

    expect($this->post->fresh()->youTubeThumbnailWarning())->toBe(ThumbnailWarning::Failed);
    Http::assertNothingSent();
});
