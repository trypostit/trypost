<?php

declare(strict_types=1);

use App\Enums\Facebook\CoverFailure;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Status as AccountStatus;
use App\Http\Resources\App\PostCardResource;
use App\Jobs\ApplyFacebookReelCover;
use App\Jobs\SetFacebookReelCover;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Support\Social\CoverRetry;
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

function runApplyFacebookReelCover(Post $post, int $attempt = 1): ApplyFacebookReelCover
{
    $job = (new ApplyFacebookReelCover($post))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;
    app()->call([$job, 'handle']);

    return $job;
}

/** Why the cover did not go on, as the post keeps it. */
function facebookCoverFailure(Post $post): ?array
{
    return data_get($post->fresh()->error_context, FacebookCover::FAILURE);
}

function thumbnailsWereAsked(): bool
{
    return Http::recorded(fn (Request $request): bool => $request->url() === facebookThumbnailsUrl())->isNotEmpty();
}

describe('waiting for the reel', function () {
    test('a reel facebook has processed hands its cover to the job that sends it', function () {
        Http::fake([facebookReelStatusUrl() => Http::response(facebookReelStatus('ready'))]);

        runSetFacebookReelCover($this->post)->assertNotReleased();

        Queue::assertPushed(ApplyFacebookReelCover::class, fn (ApplyFacebookReelCover $job): bool => $job->post->is($this->post));
        expect(thumbnailsWereAsked())->toBeFalse();
    });

    test('a reel facebook is still processing is checked again a minute later', function (string $videoStatus) {
        Http::fake([facebookReelStatusUrl() => Http::response(facebookReelStatus($videoStatus))]);

        runSetFacebookReelCover($this->post)->assertReleased(SetFacebookReelCover::CHECK_EVERY_SECONDS);

        Queue::assertNotPushed(ApplyFacebookReelCover::class);
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeFalse();
    })->with(['processing', 'upload_complete']);

    test('a status read that fails for now is checked again a minute later', function (array $fakes) {
        Http::fake($fakes);

        runSetFacebookReelCover($this->post)->assertReleased(SetFacebookReelCover::CHECK_EVERY_SECONDS);

        Queue::assertNotPushed(ApplyFacebookReelCover::class);
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeFalse();
    })->with([
        'a server error' => fn (): array => [facebookReelStatusUrl() => Http::response('', 503)],
        'facebook unreachable' => fn (): array => [facebookReelStatusUrl() => Http::failedConnection()],
    ]);

    test('a status read that keeps failing for now leaves, after the last check, a warning that says what kept failing', function () {
        Http::fake([facebookReelStatusUrl() => Http::response('<html>Bad gateway</html>', 502)]);

        runSetFacebookReelCover($this->post)->assertReleased(SetFacebookReelCover::CHECK_EVERY_SECONDS);
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeFalse();

        $job = (new SetFacebookReelCover($this->post))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        app()->call([$job, 'handle']);

        $job->assertNotReleased();
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue()
            ->and(facebookCoverFailure($this->post))->toMatchArray(['reason' => CoverFailure::StillProcessing->value, 'http_status' => 502]);
    });

    test('a reel ready after a status read failed for now keeps no failure', function () {
        Http::fake([facebookReelStatusUrl() => Http::sequence()
            ->push('<html>Bad gateway</html>', 502)
            ->push(facebookReelStatus('ready'))]);

        runSetFacebookReelCover($this->post)->assertReleased(SetFacebookReelCover::CHECK_EVERY_SECONDS);
        runSetFacebookReelCover($this->post)->assertNotReleased();

        Queue::assertPushed(ApplyFacebookReelCover::class);
        expect(facebookCoverFailure($this->post))->toBeNull();
    });

    test('a reel facebook could not process leaves a warning', function (string $videoStatus) {
        Http::fake([facebookReelStatusUrl() => Http::response(facebookReelStatus($videoStatus))]);

        runSetFacebookReelCover($this->post)->assertNotReleased();

        Queue::assertNotPushed(ApplyFacebookReelCover::class);
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue()
            ->and(facebookCoverFailure($this->post)['reason'])->toBe(CoverFailure::ProcessingFailed->value);
    })->with(['error', 'expired']);

    test('a reel facebook cannot load leaves a warning, since it may be a lost permission and not a deletion', function () {
        Http::fake([
            facebookReelStatusUrl() => Http::response(['error' => ['message' => 'Unsupported get request. Object with ID video_123 does not exist, cannot be loaded due to missing permissions', 'type' => 'GraphMethodException', 'code' => 100, 'error_subcode' => 33]], 400),
        ]);

        runSetFacebookReelCover($this->post)->assertNotReleased();

        Queue::assertNotPushed(ApplyFacebookReelCover::class);
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue()
            ->and(facebookCoverFailure($this->post))->toMatchArray(['reason' => CoverFailure::Refused->value, 'platform_error_code' => '100'])
            ->and(facebookCoverFailure($this->post)['raw_response'])->toContain('cannot be loaded due to missing permissions');
    });

    test('a page token that expired leaves a warning at once instead of checking again', function () {
        Http::fake([
            facebookReelStatusUrl() => Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]], 400),
        ]);

        runSetFacebookReelCover($this->post)->assertNotReleased();

        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue()
            ->and(facebookCoverFailure($this->post))->toBe(['reason' => CoverFailure::TokenExpired->value]);
    });

    test('a channel without the reel cover scopes leaves a warning without asking facebook', function () {
        Http::fake();
        $this->account->update(['scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts']]);

        runSetFacebookReelCover($this->post);

        Http::assertNothingSent();
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue()
            ->and(facebookCoverFailure($this->post))->toBe(['reason' => CoverFailure::NoPermission->value]);
    });

    test('a channel whose granted scopes are unknown still gets its reel cover', function (?array $scopes) {
        Http::fake([facebookReelStatusUrl() => Http::response(facebookReelStatus('ready'))]);
        $this->account->update(['scopes' => $scopes]);

        runSetFacebookReelCover($this->post)->assertNotReleased();

        Queue::assertPushed(ApplyFacebookReelCover::class);
    })->with(['null' => [null], 'empty' => [[]]]);

    test('a channel that is no longer connected leaves a warning without asking facebook', function () {
        Http::fake();
        $this->account->update(['status' => AccountStatus::TokenExpired]);

        runSetFacebookReelCover($this->post)->assertNotReleased();

        Http::assertNothingSent();
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue()
            ->and(facebookCoverFailure($this->post))->toBe(['reason' => CoverFailure::NotConnected->value]);
    });

    test('a reel without a cover asks facebook nothing', function () {
        Http::fake();
        $this->post->update(['media' => [[...$this->post->media[0], 'meta' => []]]]);

        runSetFacebookReelCover($this->post);

        Http::assertNothingSent();
        Queue::assertNotPushed(ApplyFacebookReelCover::class);
    });

    test('a reel still processing after thirty minutes of checks keeps a warning', function () {
        (new SetFacebookReelCover($this->post))->failed(null);

        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue()
            ->and(facebookCoverFailure($this->post))->toBe(['reason' => CoverFailure::StillProcessing->value]);
    });

    test('a post deleted while its reel was checked lets the job fail quietly', function () {
        Post::query()->whereKey($this->post->id)->delete();

        (new SetFacebookReelCover($this->post))->failed(null);

        expect(Post::find($this->post->id))->toBeNull();
    });

    test('a check outlasts a status read the http client tries three times and stays under the queue retry window', function () {
        $timeout = (new SetFacebookReelCover($this->post))->timeout;

        expect($timeout)->toBeGreaterThan(3 * 120)
            ->and($timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
    });
});

test('with the reel cover turned off, a reel published with a saved cover gets no cover job and no warning', function () {
    config(['trypost.platforms.facebook.scopes' => ['public_profile', 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights', 'business_management']]);
    Http::fake();

    expect(FacebookCover::isAwaitedBy($this->post->fresh()))->toBeFalse();

    runSetFacebookReelCover($this->post)->assertNotReleased();
    runApplyFacebookReelCover($this->post)->assertNotReleased();

    Http::assertNothingSent();
    Queue::assertNotPushed(ApplyFacebookReelCover::class);
    expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeFalse();
});

describe('sending the cover', function () {
    test('the cover goes to facebook as the preferred thumbnail of the reel', function () {
        Http::fake([
            FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
            facebookThumbnailsUrl() => Http::response(['success' => true]),
        ]);

        runApplyFacebookReelCover($this->post)->assertNotReleased();

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
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeFalse();
    });

    test('a cover facebook refuses leaves a warning and the reel published', function () {
        Http::fake([
            FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
            facebookThumbnailsUrl() => Http::response(['error' => ['message' => 'Invalid parameter', 'type' => 'OAuthException', 'code' => 100]], 400),
        ]);

        runApplyFacebookReelCover($this->post)->assertNotReleased();

        $post = $this->post->fresh();
        expect(FacebookCover::hasWarning($post->error_context))->toBeTrue()
            ->and(facebookCoverFailure($post))->toMatchArray(['reason' => CoverFailure::Refused->value, 'platform_error_code' => '100'])
            ->and(facebookCoverFailure($post)['raw_response'])->toContain('Invalid parameter')
            ->and($post->status->value)->toBe('published')
            ->and(PostCardResource::make($post)->resolve()['facebook_cover_warning'])->toBeTrue();
    });

    test('a failure facebook may get over is tried again a minute later without a warning', function (array $fakes, bool $asksFacebook) {
        Http::fake([
            FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
            ...$fakes,
        ]);

        runApplyFacebookReelCover($this->post)->assertReleased(CoverRetry::delayAfter(1));

        expect(thumbnailsWereAsked())->toBe($asksFacebook)
            ->and(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeFalse()
            ->and(facebookCoverFailure($this->post))->not->toBeEmpty();
    })->with([
        'a gateway error' => fn (): array => [[facebookThumbnailsUrl() => Http::response('<html>Bad gateway</html>', 502)], true],
        'a server error' => fn (): array => [[facebookThumbnailsUrl() => Http::response(['error' => ['message' => 'Service temporarily unavailable', 'type' => 'OAuthException', 'code' => 2, 'is_transient' => true]], 500)], true],
        'too many requests' => fn (): array => [[facebookThumbnailsUrl() => Http::response('', 429)], true],
        'a page rate limit' => fn (): array => [[facebookThumbnailsUrl() => Http::response(['error' => ['message' => 'Limit', 'type' => 'OAuthException', 'code' => 80001]], 400)], true],
        'facebook unreachable' => fn (): array => [[facebookThumbnailsUrl() => Http::failedConnection()], true],
        'the cover not downloading' => fn (): array => [[FACEBOOK_COVER_URL => Http::response('', 404)], false],
    ]);

    test('each try that fails for now waits longer before the next one', function (int $attempt, int $delaySeconds) {
        Http::fake([
            FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
            facebookThumbnailsUrl() => Http::response('<html>Bad gateway</html>', 502),
        ]);

        runApplyFacebookReelCover($this->post, $attempt)->assertReleased($delaySeconds);
    })->with([
        'after the first try, one minute' => [1, 60],
        'after the second, two minutes' => [2, 120],
        'after the third, five minutes' => [3, 300],
        'after the fourth, ten minutes' => [4, 600],
    ]);

    test('a page rate limit waits until facebook says the page regains access', function () {
        $this->freezeTime();
        Http::fake([
            FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
            facebookThumbnailsUrl() => Http::response(
                ['error' => ['message' => 'Limit', 'type' => 'OAuthException', 'code' => 80001]],
                400,
                ['X-Business-Use-Case-Usage' => json_encode(['page_123' => [['type' => 'pages', 'estimated_time_to_regain_access' => 30]]])],
            ),
        ]);

        runApplyFacebookReelCover($this->post)->assertReleased(30 * 60);
    });

    test('a rate limit facebook does not count as transient waits for its reset before trying again', function (int $code) {
        $this->freezeTime();
        Http::fake([
            FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
            facebookThumbnailsUrl() => Http::response(
                ['error' => ['message' => 'Limit', 'type' => 'OAuthException', 'code' => $code]],
                400,
                ['X-Business-Use-Case-Usage' => json_encode(['page_123' => [['type' => 'pages', 'estimated_time_to_regain_access' => 20]]])],
            ),
        ]);

        runApplyFacebookReelCover($this->post)->assertReleased(20 * 60);

        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeFalse()
            ->and(facebookCoverFailure($this->post))->toMatchArray(['category' => 'rate_limit', 'platform_error_code' => (string) $code]);
    })->with(['341' => 341, '613' => 613, '1349125' => 1349125]);

    test('the last try failing for now leaves a warning that says what kept failing', function () {
        expect((new ApplyFacebookReelCover($this->post))->tries)->toBe(count(CoverRetry::BACKOFF_SECONDS) + 1);
        Http::fake([
            FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
            facebookThumbnailsUrl() => Http::response('<html>Bad gateway</html>', 502),
        ]);

        runApplyFacebookReelCover($this->post, CoverRetry::TRIES)->assertNotReleased();

        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue()
            ->and(facebookCoverFailure($this->post))->toMatchArray(['reason' => CoverFailure::GaveUp->value, 'http_status' => 502])
            ->and(facebookCoverFailure($this->post)['detail'])->toContain('Bad gateway');
    });

    test('a cover that goes on after a failed try keeps no failure', function () {
        Http::fake([
            FACEBOOK_COVER_URL => fn () => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
            facebookThumbnailsUrl() => Http::sequence()
                ->push('<html>Bad gateway</html>', 502)
                ->push(['success' => true]),
        ]);

        runApplyFacebookReelCover($this->post)->assertReleased(60);
        expect(facebookCoverFailure($this->post))->not->toBeNull();

        runApplyFacebookReelCover($this->post, 2)->assertNotReleased();

        expect(facebookCoverFailure($this->post))->toBeNull()
            ->and(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeFalse();
    });

    test('a cover that cannot be made a jpeg leaves a warning at once instead of trying again', function () {
        Http::fake([
            FACEBOOK_COVER_URL => Http::response('not an image', 200, ['Content-Type' => 'image/png']),
            facebookThumbnailsUrl() => Http::response(['success' => true]),
        ]);

        runApplyFacebookReelCover($this->post)->assertNotReleased();

        expect(thumbnailsWereAsked())->toBeFalse()
            ->and(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue()
            ->and(facebookCoverFailure($this->post))->toMatchArray(['reason' => CoverFailure::InvalidImage->value, 'category' => 'media_format']);
    });

    test('a page token that expired leaves a warning at once instead of trying again', function () {
        Http::fake([
            FACEBOOK_COVER_URL => Http::response(facebookCoverPng(), 200, ['Content-Type' => 'image/png']),
            facebookThumbnailsUrl() => Http::response(['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]], 400),
        ]);

        runApplyFacebookReelCover($this->post)->assertNotReleased();

        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue();
    });

    test('a channel that lost the reel cover scopes since the reel was ready leaves a warning without asking facebook', function () {
        Http::fake();
        $this->account->update(['scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts']]);

        runApplyFacebookReelCover($this->post)->assertNotReleased();

        Http::assertNothingSent();
        expect(FacebookCover::hasWarning($this->post->fresh()->error_context))->toBeTrue();
    });

    test('a post deleted while its cover was tried again lets the job fail quietly', function () {
        Post::query()->whereKey($this->post->id)->delete();

        (new ApplyFacebookReelCover($this->post))->failed(null);

        expect(Post::find($this->post->id))->toBeNull();
    });

    test('a try outlasts its download and an upload the http client tries three times and stays under the queue retry window', function () {
        $timeout = (new ApplyFacebookReelCover($this->post))->timeout;

        expect($timeout)->toBeGreaterThan(4 * 120)
            ->and($timeout)->toBeLessThan(config('queue.connections.redis.retry_after'));
    });
});
