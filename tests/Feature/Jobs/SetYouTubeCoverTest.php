<?php

declare(strict_types=1);

use App\Enums\Post\PublishStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Enums\YouTube\ThumbnailWarning;
use App\Exceptions\TokenExpiredException;
use App\Http\Resources\App\PostCardResource;
use App\Jobs\ApplyYouTubeCover;
use App\Jobs\SetYouTubeCover;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Services\Social\ConnectionVerifier;
use App\Support\Social\CoverRetry;
use App\Support\Social\YouTubeCover;
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
function fakeYouTubeClient(): void
{
    $client = new Client;
    $client->setHttpClient(Http::buildClient());
    app()->instance(Client::class, $client);
}

function runSetYouTubeCover(Post $post): SetYouTubeCover
{
    fakeYouTubeClient();

    $job = (new SetYouTubeCover($post))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function runApplyYouTubeCover(Post $post, int $attempt = 1): ApplyYouTubeCover
{
    fakeYouTubeClient();

    $job = (new ApplyYouTubeCover($post))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    app()->call([$job, 'handle']);

    return $job;
}

function youTubeThumbnailWasSet(): bool
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/thumbnails/set'))->isNotEmpty();
}

describe('waiting for the video', function () {
    test('a processed video hands its cover to the job that sets it', function () {
        Http::fake([YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing('succeeded'))]);

        runSetYouTubeCover($this->post)->assertNotReleased();

        Queue::assertPushed(ApplyYouTubeCover::class, fn (ApplyYouTubeCover $job): bool => $job->post->is($this->post));
        expect(youTubeThumbnailWasSet())->toBeFalse();
    });

    test('a video still processing is checked again a minute later', function () {
        Http::fake([YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing('processing'))]);

        runSetYouTubeCover($this->post)->assertReleased(delay: SetYouTubeCover::CHECK_EVERY_SECONDS);

        Queue::assertNotPushed(ApplyYouTubeCover::class);
    });

    test('a status read youtube fails for now is checked again a minute later', function () {
        Http::fake([YOUTUBE_VIDEOS_URL => Http::response(['error' => ['code' => 503, 'message' => 'backend']], 503)]);

        runSetYouTubeCover($this->post)->assertReleased(delay: SetYouTubeCover::CHECK_EVERY_SECONDS);

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBeNull();
    });

    test('a video youtube could not process leaves the failed warning', function (string $status) {
        Http::fake([YOUTUBE_VIDEOS_URL => Http::response(youTubeProcessing($status))]);

        runSetYouTubeCover($this->post)->assertNotReleased();

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBe(ThumbnailWarning::Failed);
        Queue::assertNotPushed(ApplyYouTubeCover::class);
    })->with(['failed', 'terminated']);

    test('a video youtube no longer lists leaves no warning', function () {
        Http::fake([YOUTUBE_VIDEOS_URL => Http::response(['items' => []])]);

        runSetYouTubeCover($this->post)->assertNotReleased();

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBeNull();
        Queue::assertNotPushed(ApplyYouTubeCover::class);
    });

    test('a revoked token leaves the failed warning at once instead of checking again', function () {
        $this->account->update(['token_expires_at' => now()->subMinute()]);
        $verifier = Mockery::mock(ConnectionVerifier::class);
        $verifier->shouldReceive('refreshToken')->once()->andThrow(new TokenExpiredException('revoked'));
        $this->app->instance(ConnectionVerifier::class, $verifier);
        Http::fake();

        runSetYouTubeCover($this->post)->assertNotReleased();

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBe(ThumbnailWarning::Failed);
    });

    test('a post without a cover image asks youtube nothing', function () {
        $this->post->update(['media' => [[...$this->post->media[0], 'meta' => []]]]);
        Http::fake();

        runSetYouTubeCover($this->post);

        Http::assertNothingSent();
        Queue::assertNotPushed(ApplyYouTubeCover::class);
    });

    test('a channel no longer connected leaves the failed warning', function () {
        $this->account->update(['status' => AccountStatus::TokenExpired]);
        Http::fake();

        runSetYouTubeCover($this->post);

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBe(ThumbnailWarning::Failed);
        Http::assertNothingSent();
    });

    test('a video still processing after thirty minutes of checks leaves the failed warning', function () {
        (new SetYouTubeCover($this->post))->failed(null);

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBe(ThumbnailWarning::Failed);
    });

    test('a post deleted while its video was checked lets the job fail quietly', function () {
        Post::query()->whereKey($this->post->id)->delete();

        (new SetYouTubeCover($this->post))->failed(null);

        expect(Post::find($this->post->id))->toBeNull();
    });

    test('a check outlasts its two slow requests and stays under the queue retry window', function () {
        $timeout = (new SetYouTubeCover($this->post))->timeout;

        expect($timeout)->toBeGreaterThan(2 * 120)
            ->and($timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
    });
});

describe('setting the cover', function () {
    test('the cover image becomes the thumbnail of the video', function () {
        Http::fake([
            YOUTUBE_COVER_URL => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
            YOUTUBE_THUMBNAILS_URL => Http::response(['kind' => 'youtube#thumbnailSetResponse', 'items' => []]),
        ]);

        runApplyYouTubeCover($this->post)->assertNotReleased();

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/thumbnails/set')
            && str_contains($request->url(), 'videoId=short-id')
            && $request->body() === 'jpeg bytes');
        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBeNull();
    });

    test('a cover YouTube refuses leaves a warning and the post published', function (int $status, ThumbnailWarning $warning) {
        Http::fake([
            YOUTUBE_COVER_URL => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
            YOUTUBE_THUMBNAILS_URL => Http::response(['error' => ['code' => $status, 'message' => 'refused', 'errors' => [['reason' => 'refused']]]], $status),
        ]);

        runApplyYouTubeCover($this->post)->assertNotReleased();

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBe($warning)
            ->and($this->post->fresh()->publish_status)->toBe(PublishStatus::Published);
    })->with([
        'channel not verified' => [403, ThumbnailWarning::Forbidden],
        'daily limit' => [429, ThumbnailWarning::DailyLimit],
        'invalid image' => [400, ThumbnailWarning::InvalidImage],
    ]);

    test('a failure youtube may get over is tried again a minute later without a warning', function (array $fakes, bool $asksYouTube) {
        Http::fake([
            YOUTUBE_COVER_URL => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
            ...$fakes,
        ]);

        runApplyYouTubeCover($this->post)->assertReleased(CoverRetry::delayAfter(1));

        expect(youTubeThumbnailWasSet())->toBe($asksYouTube)
            ->and(YouTubeCover::warning($this->post->fresh()->error_context))->toBeNull();
    })->with([
        'a server error' => fn (): array => [[YOUTUBE_THUMBNAILS_URL => Http::response(['error' => ['code' => 503, 'message' => 'backend']], 503)], true],
        'the cover not downloading' => fn (): array => [[YOUTUBE_COVER_URL => Http::response('', 404)], false],
    ]);

    test('each try that fails for now waits longer before the next one', function (int $attempt, int $delaySeconds) {
        Http::fake([
            YOUTUBE_COVER_URL => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
            YOUTUBE_THUMBNAILS_URL => Http::response(['error' => ['code' => 503, 'message' => 'backend']], 503),
        ]);

        runApplyYouTubeCover($this->post, $attempt)->assertReleased($delaySeconds);
    })->with([
        'after the first try, one minute' => [1, 60],
        'after the second, two minutes' => [2, 120],
        'after the third, five minutes' => [3, 300],
        'after the fourth, ten minutes' => [4, 600],
    ]);

    test('the last try failing for now leaves the failed warning', function () {
        expect((new ApplyYouTubeCover($this->post))->tries)->toBe(count(CoverRetry::BACKOFF_SECONDS) + 1);

        (new ApplyYouTubeCover($this->post))->failed(null);

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBe(ThumbnailWarning::Failed);
    });

    test('a cover that is not a jpeg or a png reaches youtube as a jpeg', function () {
        $this->post->update(['media' => [[...$this->post->media[0], 'meta' => ['cover' => [
            ...data_get($this->post->media, '0.meta.cover'),
            'mime_type' => 'image/webp',
        ]]]]]);
        $webp = (string) (new ImageManager(Driver::class))->createImage(320, 568)->fill('336699')->encodeUsingMediaType('image/webp');
        Http::fake([
            YOUTUBE_COVER_URL => Http::response($webp, 200, ['Content-Type' => 'image/webp']),
            YOUTUBE_THUMBNAILS_URL => Http::response(['kind' => 'youtube#thumbnailSetResponse', 'items' => []]),
        ]);

        runApplyYouTubeCover($this->post);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/thumbnails/set')
            && $request->header('Content-Type')[0] === 'image/jpeg'
            && str_starts_with($request->body(), "\xFF\xD8"));
    });

    test('a cover that cannot be made a jpeg leaves the invalid image warning at once', function () {
        $this->post->update(['media' => [[...$this->post->media[0], 'meta' => ['cover' => [
            ...data_get($this->post->media, '0.meta.cover'),
            'mime_type' => 'image/webp',
        ]]]]]);
        Http::fake([YOUTUBE_COVER_URL => Http::response('not an image', 200, ['Content-Type' => 'image/webp'])]);

        runApplyYouTubeCover($this->post)->assertNotReleased();

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBe(ThumbnailWarning::InvalidImage)
            ->and(youTubeThumbnailWasSet())->toBeFalse();
    });

    test('a video removed before its thumbnail was set leaves no warning', function () {
        Http::fake([
            YOUTUBE_COVER_URL => Http::response('jpeg bytes', 200, ['Content-Type' => 'image/jpeg']),
            YOUTUBE_THUMBNAILS_URL => Http::response(['error' => ['code' => 404, 'message' => 'not found', 'errors' => [['reason' => 'videoNotFound']]]], 404),
        ]);

        runApplyYouTubeCover($this->post)->assertNotReleased();

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBeNull();
    });

    test('a revoked token leaves the failed warning at once instead of trying again', function () {
        $this->account->update(['token_expires_at' => now()->subMinute()]);
        $verifier = Mockery::mock(ConnectionVerifier::class);
        $verifier->shouldReceive('refreshToken')->once()->andThrow(new TokenExpiredException('revoked'));
        $this->app->instance(ConnectionVerifier::class, $verifier);
        Http::fake();

        runApplyYouTubeCover($this->post)->assertNotReleased();

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBe(ThumbnailWarning::Failed);
    });

    test('a channel no longer connected leaves the failed warning without asking youtube', function () {
        $this->account->update(['status' => AccountStatus::TokenExpired]);
        Http::fake();

        runApplyYouTubeCover($this->post)->assertNotReleased();

        expect(YouTubeCover::warning($this->post->fresh()->error_context))->toBe(ThumbnailWarning::Failed);
        Http::assertNothingSent();
    });

    test('a post deleted while its cover was tried again lets the job fail quietly', function () {
        Post::query()->whereKey($this->post->id)->delete();

        (new ApplyYouTubeCover($this->post))->failed(null);

        expect(Post::find($this->post->id))->toBeNull();
    });

    test('a try outlasts its three slow requests and stays under the queue retry window', function () {
        $timeout = (new ApplyYouTubeCover($this->post))->timeout;

        expect($timeout)->toBeGreaterThan(3 * 120)
            ->and($timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
    });
});

test('the post card shows the cover warning without a failure', function () {
    YouTubeCover::recordWarning($this->post, ThumbnailWarning::Forbidden);

    $card = PostCardResource::make($this->post->fresh())->resolve();

    expect($card['youtube_thumbnail_warning'])->toBe('forbidden')
        ->and($card['failure'])->toBeNull();
});
