<?php

declare(strict_types=1);

use App\Actions\Analytics\UpsertAnalyticsPublication;
use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\Analytics\PublicationOrigin;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Metrics\BlueskyPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\FacebookPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\InstagramPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\MastodonPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\PinterestPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use App\Services\Analytics\Collectors\Metrics\ThreadsPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\TikTokPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\XPublicationMetricsCollector;
use App\Services\Analytics\Collectors\Metrics\YouTubePublicationMetricsCollector;
use App\Services\Analytics\Collectors\Publications\FacebookPublicationCollector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('the metric factory includes every v1 platform and excludes v2 and messaging platforms', function (Platform $platform, string $collector) {
    expect(app(PublicationMetricsCollectorFactory::class)->for($platform))->toBeInstanceOf($collector);
})->with([
    [Platform::Instagram, InstagramPublicationMetricsCollector::class],
    [Platform::InstagramFacebook, InstagramPublicationMetricsCollector::class],
    [Platform::Facebook, FacebookPublicationMetricsCollector::class],
    [Platform::Threads, ThreadsPublicationMetricsCollector::class],
    [Platform::X, XPublicationMetricsCollector::class],
    [Platform::Pinterest, PinterestPublicationMetricsCollector::class],
    [Platform::YouTube, YouTubePublicationMetricsCollector::class],
    [Platform::TikTok, TikTokPublicationMetricsCollector::class],
    [Platform::Bluesky, BlueskyPublicationMetricsCollector::class],
    [Platform::Mastodon, MastodonPublicationMetricsCollector::class],
]);

test('bluesky normalizes measured zero and does not fabricate omitted counts', function () {
    Http::fake(['*' => Http::response(['posts' => [[
        'likeCount' => 0,
        'replyCount' => 3,
        'repostCount' => 2,
    ]]])]);
    $account = SocialAccount::factory()->bluesky()->create();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Bluesky,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'record-key',
    ]);

    $observation = app(BlueskyPublicationMetricsCollector::class)->collect(
        $publication,
        CarbonImmutable::parse('2026-09-23', 'UTC'),
    );
    $metrics = collect($observation->metrics)->keyBy(fn ($metric) => $metric->key->value);

    expect($metrics[MetricKey::Reactions->value]->value)->toBe(0)
        ->and($metrics[MetricKey::Comments->value]->value)->toBe(3)
        ->and($metrics[MetricKey::Shares->value]->value)->toBe(2)
        ->and($metrics->has(MetricKey::Quotes->value))->toBeFalse();
});

test('provider metric responses normalize measured values without inventing omitted metrics', function (
    Platform $platform,
    array $response,
    array $expected,
    PublicationContentType $contentType = PublicationContentType::Image,
) {
    Http::fake(['*' => Http::response($response)]);
    $account = SocialAccount::factory()->create(['platform' => $platform]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => $platform,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => $platform === Platform::Facebook ? 'page_123456789' : '123456789',
        'content_type' => $contentType,
    ]);

    $observation = app(PublicationMetricsCollectorFactory::class)
        ->for($platform)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'));
    $actual = collect($observation->metrics)->mapWithKeys(fn ($metric) => [$metric->key->value => $metric->value])->all();

    expect($actual)->toBe($expected);
})->with([
    'instagram' => [Platform::Instagram, ['data' => [
        ['name' => 'reach', 'values' => [['value' => 100]]],
        ['name' => 'likes', 'total_value' => ['value' => 0]],
        ['name' => 'comments', 'values' => [['value' => 2]]],
        ['name' => 'saved', 'values' => [['value' => 1]]],
    ]], ['reach' => 100, 'reactions' => 0, 'comments' => 2, 'saves' => 1, 'engagements' => 3]],
    'facebook feed' => [Platform::Facebook, ['data' => [
        ['name' => 'post_media_view', 'values' => [['value' => 120]]],
        ['name' => 'post_reactions_like_total', 'values' => [['value' => 0]]],
    ]], ['impressions' => 120, 'reactions' => 0, 'engagements' => 0]],
    'threads' => [Platform::Threads, ['data' => [
        ['name' => 'views', 'total_value' => ['value' => 40]],
        ['name' => 'likes', 'values' => [['value' => 0]]],
        ['name' => 'reposts', 'values' => [['value' => 3]]],
    ]], ['views' => 40, 'reactions' => 0, 'shares' => 3, 'engagements' => 3]],
    'x' => [Platform::X, ['data' => ['public_metrics' => [
        'impression_count' => 200, 'like_count' => 0, 'retweet_count' => 3,
        'bookmark_count' => 4,
    ]]], ['impressions' => 200, 'reactions' => 0, 'shares' => 3, 'bookmarks' => 4, 'engagements' => 7]],
    'pinterest' => [Platform::Pinterest, ['all' => ['summary_metrics' => [
        'IMPRESSION' => 30, 'SAVE' => 0, 'PIN_CLICK' => 2,
    ]]], ['impressions' => 30, 'saves' => 0, 'pin_clicks' => 2, 'engagements' => 2]],
    'youtube' => [Platform::YouTube, [
        'columnHeaders' => [['name' => 'views'], ['name' => 'estimatedMinutesWatched'], ['name' => 'averageViewDuration'], ['name' => 'likes']],
        'rows' => [[231, 2.5, 23.4, 0]],
        'items' => [['id' => '123456789', 'statistics' => []]],
    ], ['views' => 231, 'reactions' => 0, 'watch_time_milliseconds' => 150000, 'average_watch_time_milliseconds' => 23400, 'engagements' => 0]],
    'tiktok' => [Platform::TikTok, ['data' => ['videos' => [[
        'id' => '123456789', 'view_count' => 400, 'like_count' => 0, 'comment_count' => 3,
    ]]]], ['views' => 400, 'reactions' => 0, 'comments' => 3, 'engagements' => 3]],
    'mastodon' => [Platform::Mastodon, [
        'favourites_count' => 0, 'replies_count' => 2, 'reblogs_count' => 1,
    ], ['reactions' => 0, 'comments' => 2, 'shares' => 1, 'engagements' => 3]],
]);

test('pinterest preserves its rolling window basis', function () {
    Http::fake(['*' => Http::response(['all' => ['summary_metrics' => ['SAVE' => 0]]])]);
    $account = SocialAccount::factory()->create(['platform' => Platform::Pinterest]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => $account->platform_user_id,
    ]);

    $metric = app(PinterestPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'))->metrics[0];

    expect($metric->timeBasis)->toBe(MetricTimeBasis::Rolling90Days)
        ->and($metric->value)->toBe(0);
});

test('youtube falls back to current video statistics before Analytics has processed a new video', function () {
    $analyticsApi = rtrim((string) config('trypost.platforms.youtube.analytics_api'), '/');
    $dataApi = rtrim((string) config('trypost.platforms.youtube.data_api'), '/');
    Http::fake([
        "{$analyticsApi}/reports*" => Http::response([
            'columnHeaders' => [['name' => 'views'], ['name' => 'likes']],
            'rows' => [],
        ]),
        "{$dataApi}/videos*" => Http::response(['items' => [[
            'id' => 'video-new',
            'statistics' => ['viewCount' => '231', 'likeCount' => '0', 'commentCount' => '4'],
        ]]]),
    ]);
    $account = SocialAccount::factory()->create(['platform' => Platform::YouTube]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::YouTube,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'video-new',
    ]);

    $observation = app(YouTubePublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'));
    $metrics = collect($observation->metrics)->keyBy(fn ($metric) => $metric->key->value);

    expect($metrics->map(fn ($metric) => $metric->value)->all())->toBe([
        'views' => 231,
        'reactions' => 0,
        'comments' => 4,
        'engagements' => 4,
    ])
        ->and($metrics[MetricKey::Views->value]->timeBasis)->toBe(MetricTimeBasis::Lifetime);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reports')
        && $request['filters'] === 'video==video-new');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/videos')
        && $request['part'] === 'statistics'
        && $request['id'] === 'video-new');
});

test('a rate-limited metric request throws a retryable collection exception', function () {
    Http::fake(['*' => Http::response(['error' => 'rate limited'], 429, ['Retry-After' => '120'])]);
    $account = SocialAccount::factory()->create(['platform' => Platform::Mastodon]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Mastodon,
        'platform_user_id' => $account->platform_user_id,
    ]);

    try {
        app(MastodonPublicationMetricsCollector::class)->collect($publication, CarbonImmutable::today('UTC'));
        test()->fail('Expected rate limit classification.');
    } catch (AnalyticsCollectionException $exception) {
        expect($exception->category)->toBe('rate_limited')
            ->and($exception->retryAt)->not->toBeNull();
    }
});

test('instagram reels collect watch duration in milliseconds without losing engagement', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::Instagram]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => $account->platform_user_id,
        'content_type' => PublicationContentType::Reel,
    ]);
    Http::fake(['*' => Http::sequence()
        ->push(['data' => [
            ['name' => 'reach', 'values' => [['value' => 80]]],
            ['name' => 'likes', 'total_value' => ['value' => 5]],
        ]])
        ->push(['data' => []])
        ->push(['data' => [
            ['name' => 'ig_reels_video_view_total_time', 'total_value' => ['value' => 185000]],
            ['name' => 'ig_reels_avg_watch_time', 'values' => [['value' => 23000]]],
        ]])]);

    $metrics = collect(app(InstagramPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::today('UTC'))->metrics)
        ->mapWithKeys(fn ($metric) => [$metric->key->value => $metric->value]);

    expect($metrics->all())->toBe([
        'reach' => 80,
        'reactions' => 5,
        'watch_time_milliseconds' => 185000,
        'average_watch_time_milliseconds' => 23000,
        'engagements' => 5,
    ]);
    Http::assertSentCount(3);
});

test('an optional Instagram Reel insight rate limit stays retryable', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => $account->platform_user_id,
        'content_type' => PublicationContentType::Reel,
    ]);
    Http::fake(['*' => Http::sequence()
        ->push(['data' => [['name' => 'reach', 'values' => [['value' => 80]]]]])
        ->push(['data' => []])
        ->push(['error' => ['code' => 4]], 429)]);

    expect(fn () => app(InstagramPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::today('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('rate_limited'));

    Http::assertSentCount(3);
});

test('an unsupported optional Instagram insight does not discard base metrics', function () {
    $account = SocialAccount::factory()->instagram()->create();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => $account->platform_user_id,
        'content_type' => PublicationContentType::Reel,
    ]);
    Http::fake(['*' => Http::sequence()
        ->push(['data' => [['name' => 'reach', 'values' => [['value' => 80]]]]])
        ->push(['data' => []])
        ->push(['error' => ['code' => 200]], 400)]);

    $metrics = collect(app(InstagramPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::today('UTC'))->metrics);

    expect($metrics->firstWhere('key', MetricKey::Reach)?->value)->toBe(80);
});

test('an imported Facebook video fetches video insights through its attachment target', function () {
    $graph = rtrim((string) config('trypost.platforms.facebook.graph_api'), '/');
    $account = SocialAccount::factory()->facebook()->create();
    Http::fake([
        "{$graph}/{$account->platform_user_id}/published_posts*" => Http::response(['data' => [[
            'id' => 'page_video',
            'created_time' => '2026-09-20T12:00:00+0000',
            'attachments' => ['data' => [[
                'media_type' => 'video',
                'target' => ['id' => 'video-123'],
            ]]],
        ]]]),
        "{$graph}/video-123/video_insights*" => Http::response(['data' => [[
            'name' => 'fb_reels_total_plays',
            'values' => [['value' => 42]],
        ]]]),
        "{$graph}/video-123*" => Http::response(['picture' => 'https://example.com/video.jpg']),
        "{$graph}/page_video*" => Http::response(['reactions' => ['summary' => ['total_count' => 3]]]),
    ]);

    $page = app(FacebookPublicationCollector::class)->page(
        $account,
        null,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );
    $publication = app(UpsertAnalyticsPublication::class)->external($account, $page->publications[0]);
    $metrics = collect(app(FacebookPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'))->metrics)
        ->keyBy(fn ($metric) => $metric->key->value);

    expect($publication->remote_id)->toBe('page_video')
        ->and($publication->provider_metadata)->toBe(['video_id' => 'video-123'])
        ->and($metrics[MetricKey::Views->value]->value)->toBe(42)
        ->and($metrics[MetricKey::Reactions->value]->value)->toBe(3);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/video-123/video_insights'));
});

test('X requests only public fields outside the private-metric window', function () {
    Http::fake(['*' => Http::response(['data' => ['public_metrics' => ['like_count' => 0]]])]);
    $account = SocialAccount::factory()->create(['platform' => Platform::X]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::X,
        'platform_user_id' => $account->platform_user_id,
        'provider_published_at' => CarbonImmutable::now('UTC')->subDays(40),
    ]);

    app(XPublicationMetricsCollector::class)->collect($publication, CarbonImmutable::today('UTC'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'tweet.fields=public_metrics'));
});

test('Meta rate limiting in HTTP 400 is classified as retryable', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 80002]], 400)]);
    $account = SocialAccount::factory()->create(['platform' => Platform::InstagramFacebook]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => $account->platform_user_id,
    ]);

    expect(fn () => app(InstagramPublicationMetricsCollector::class)->collect($publication, CarbonImmutable::today('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('rate_limited'));
});

test('Meta publication metrics classify missing permission on HTTP 400', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 200]], 400)]);
    $account = SocialAccount::factory()->instagram()->create();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => $account->platform_user_id,
    ]);

    expect(fn () => app(InstagramPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::today('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
});

test('TikTok resolves a TryPost publish id before collecting the public video', function () {
    $account = SocialAccount::factory()->create(['platform' => Platform::TikTok]);
    $post = Post::factory()->create(['workspace_id' => $account->workspace_id]);
    $postPlatform = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::TikTok,
        'content_type' => ContentType::TikTokVideo,
        'platform_post_id' => 'v_pub_abc',
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'post_platform_id' => $postPlatform->id,
        'network' => Platform::TikTok->network(),
        'platform' => Platform::TikTok,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'v_pub_abc',
        'origin' => PublicationOrigin::TryPost,
    ]);
    Http::fake(['*' => Http::sequence()
        ->push(['data' => ['publicaly_available_post_id' => ['123456789']]])
        ->push(['error' => ['code' => 'ok'], 'data' => ['videos' => [[
            'id' => '123456789', 'view_count' => 12, 'like_count' => 0,
        ]]]])]);

    $metrics = collect(app(TikTokPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::today('UTC'))->metrics)
        ->mapWithKeys(fn ($metric) => [$metric->key->value => $metric->value]);

    expect($metrics->all())->toBe(['views' => 12, 'reactions' => 0, 'engagements' => 0])
        ->and($publication->fresh()->remote_id)->toBe('v_pub_abc')
        ->and($postPlatform->fresh()->platform_post_id)->toBe('v_pub_abc');
    Http::assertSentCount(2);
});

test('youtube collects current statistics and every Analytics metric for a short', function () {
    $analyticsApi = rtrim((string) config('trypost.platforms.youtube.analytics_api'), '/');
    $dataApi = rtrim((string) config('trypost.platforms.youtube.data_api'), '/');
    Http::fake([
        "{$analyticsApi}/reports*" => Http::response([
            'kind' => 'youtubeAnalytics#resultTable',
            'columnHeaders' => collect([
                'views', 'engagedViews', 'estimatedMinutesWatched', 'averageViewDuration', 'averageViewPercentage',
                'likes', 'comments', 'shares', 'videosAddedToPlaylists', 'subscribersGained', 'subscribersLost',
            ])->map(fn (string $name): array => ['name' => $name, 'columnType' => 'METRIC', 'dataType' => 'INTEGER'])->all(),
            'rows' => [[1010, 640, 152, 9, 31.4, 24, 0, 3, 2, 1, 0]],
        ]),
        "{$dataApi}/videos*" => Http::response(['kind' => 'youtube#videoListResponse', 'items' => [[
            'kind' => 'youtube#video',
            'id' => 'short-1',
            'statistics' => ['viewCount' => '1144', 'likeCount' => '26', 'favoriteCount' => '0', 'commentCount' => '0'],
        ]]]),
    ]);
    $account = SocialAccount::factory()->youtube()->create();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::YouTube,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'short-1',
        'content_type' => PublicationContentType::Short,
    ]);

    $observation = app(YouTubePublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'));

    expect(collect($observation->metrics)->mapWithKeys(fn ($metric) => [$metric->key->value => $metric->value])->all())->toEqual([
        'views' => 1144,
        'reactions' => 26,
        'comments' => 0,
        'engaged_views' => 640,
        'watch_time_milliseconds' => 9120000,
        'average_watch_time_milliseconds' => 9000,
        'average_percentage_viewed' => 31.4,
        'shares' => 3,
        'saves' => 2,
        'subscribers_gained' => 1,
        'subscribers_lost' => 0,
        'engagements' => 31,
    ]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reports')
        && str_contains((string) $request['metrics'], 'videosAddedToPlaylists'));
});

test('youtube keeps current video statistics when Analytics refuses the report', function () {
    $analyticsApi = rtrim((string) config('trypost.platforms.youtube.analytics_api'), '/');
    $dataApi = rtrim((string) config('trypost.platforms.youtube.data_api'), '/');
    Http::fake([
        "{$analyticsApi}/reports*" => Http::response(['error' => [
            'code' => 403,
            'message' => 'Forbidden',
            'errors' => [['message' => 'Forbidden', 'domain' => 'global', 'reason' => 'forbidden']],
        ]], 403),
        "{$dataApi}/videos*" => Http::response(['items' => [[
            'id' => 'short-1',
            'statistics' => ['viewCount' => '1144', 'likeCount' => '26', 'commentCount' => '0'],
        ]]]),
    ]);
    $account = SocialAccount::factory()->youtube()->create();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::YouTube,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'short-1',
        'content_type' => PublicationContentType::Short,
    ]);

    $observation = app(YouTubePublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'));

    expect(collect($observation->metrics)->mapWithKeys(fn ($metric) => [$metric->key->value => $metric->value])->all())
        ->toEqual(['views' => 1144, 'reactions' => 26, 'comments' => 0, 'engagements' => 26]);
});

test('an expired youtube token is refreshed before publication metrics are requested', function () {
    $analyticsApi = rtrim((string) config('trypost.platforms.youtube.analytics_api'), '/');
    $dataApi = rtrim((string) config('trypost.platforms.youtube.data_api'), '/');
    Http::fake([
        config('trypost.platforms.youtube.oauth_api').'/token' => Http::response([
            'access_token' => 'fresh-token',
            'expires_in' => 3599,
            'scope' => 'https://www.googleapis.com/auth/yt-analytics.readonly',
            'token_type' => 'Bearer',
        ]),
        "{$analyticsApi}/reports*" => Http::response(['columnHeaders' => [['name' => 'views']], 'rows' => [[5]]]),
        "{$dataApi}/videos*" => Http::response(['items' => [['id' => 'short-1', 'statistics' => ['viewCount' => '7']]]]),
    ]);
    $account = SocialAccount::factory()->youtube()->createQuietly([
        'access_token' => 'stale-token',
        'refresh_token' => 'refresh-token',
        'token_expires_at' => now()->subHour(),
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::YouTube,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'short-1',
    ]);

    app(YouTubePublicationMetricsCollector::class)->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'));

    Http::assertNotSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer stale-token'));
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/reports')
        && $request->hasHeader('Authorization', 'Bearer fresh-token'));
    expect($account->fresh()->access_token)->toBe('fresh-token');
});

test('a rejected token refresh is classified as an authentication failure', function () {
    Http::fake([
        config('trypost.platforms.youtube.oauth_api').'/token' => Http::response([
            'error' => 'invalid_grant',
            'error_description' => 'Token has been expired or revoked.',
        ], 400),
    ]);
    $account = SocialAccount::factory()->youtube()->createQuietly(['token_expires_at' => now()->subHour()]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::YouTube,
        'platform_user_id' => $account->platform_user_id,
    ]);

    expect(fn () => app(YouTubePublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('authentication'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/videos')
        || str_contains($request->url(), '/reports'));
});

test('mastodon reads a public status without a token that lacks read scope', function (array $scopes, bool $authenticated) {
    Http::fake(['https://mastodon.social/api/v1/statuses/*' => Http::response([
        'id' => '117304063461175259',
        'visibility' => 'public',
        'replies_count' => 1,
        'reblogs_count' => 2,
        'favourites_count' => 3,
    ])]);
    $account = SocialAccount::factory()->mastodon()->create(['scopes' => $scopes]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Mastodon,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => '117304063461175259',
    ]);

    $observation = app(MastodonPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'));

    expect(collect($observation->metrics)->mapWithKeys(fn ($metric) => [$metric->key->value => $metric->value])->all())
        ->toBe(['reactions' => 3, 'comments' => 1, 'shares' => 2, 'engagements' => 6]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/statuses/')
        && $request->hasHeader('Authorization') === $authenticated);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/statuses/')
        && $request->hasHeader('Authorization') !== $authenticated);
})->with([
    'publish-only token' => [['read:accounts', 'write:statuses', 'write:media'], false],
    'token with read:statuses' => [['read:accounts', 'read:statuses', 'write:statuses', 'write:media'], true],
]);

test('facebook keeps public post counts when Page insights are not available', function () {
    $graph = rtrim((string) config('trypost.platforms.facebook.graph_api'), '/');
    $account = SocialAccount::factory()->facebook()->create();
    Http::fake([
        "{$graph}/page_post/insights*" => Http::response(['error' => [
            'message' => '(#200) Permissions error',
            'type' => 'OAuthException',
            'code' => 200,
            'fbtrace_id' => 'trace',
        ]], 403),
        "{$graph}/page_post*" => Http::response([
            'id' => 'page_post',
            'reactions' => ['data' => [], 'summary' => ['total_count' => 5, 'viewer_reaction' => 'NONE']],
            'comments' => ['data' => [], 'summary' => ['order' => 'ranked', 'total_count' => 2, 'can_comment' => true]],
            'shares' => ['count' => 1],
        ]),
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'page_post',
        'content_type' => PublicationContentType::Image,
    ]);

    $observation = app(FacebookPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'));

    expect(collect($observation->metrics)->mapWithKeys(fn ($metric) => [$metric->key->value => $metric->value])->all())
        ->toBe(['reactions' => 5, 'comments' => 2, 'shares' => 1, 'engagements' => 8]);
});

test('pinterest requests only documented pin metric types and keeps lifetime comments and reactions', function () {
    $api = rtrim((string) config('trypost.platforms.pinterest.api'), '/');
    Http::fake(["{$api}/pins/*/analytics*" => Http::response(['all' => [
        'daily_metrics' => [],
        'summary_metrics' => ['IMPRESSION' => 240, 'SAVE' => 20, 'PIN_CLICK' => 37, 'OUTBOUND_CLICK' => 3, 'SAVE_RATE' => 0.0833],
        'lifetime_metrics' => ['TOTAL_COMMENTS' => 2, 'TOTAL_REACTIONS' => 12],
    ]])]);
    $account = SocialAccount::factory()->pinterest()->createQuietly();
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => '813744226420795884',
        'content_type' => PublicationContentType::Image,
    ]);

    $observation = app(PinterestPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'));

    expect(collect($observation->metrics)->mapWithKeys(fn ($metric) => [$metric->key->value => $metric->value])->all())->toEqual([
        'impressions' => 240,
        'saves' => 20,
        'pin_clicks' => 37,
        'outbound_clicks' => 3,
        'save_rate' => 8.33,
        'comments' => 2,
        'reactions' => 12,
        'engagements' => 74,
    ]);
    Http::assertSent(function (Request $request): bool {
        $types = explode(',', (string) data_get($request->data(), 'metric_types'));

        return str_contains($request->url(), '/analytics')
            && in_array('TOTAL_COMMENTS', $types, true)
            && in_array('TOTAL_REACTIONS', $types, true)
            && array_diff($types, ['IMPRESSION', 'OUTBOUND_CLICK', 'PIN_CLICK', 'SAVE', 'SAVE_RATE', 'TOTAL_COMMENTS', 'TOTAL_REACTIONS']) === [];
    });
});

test('meta collectors send the token through the shared refreshing path', function () {
    $account = SocialAccount::factory()->instagram()->createQuietly([
        'access_token' => 'expiring-token',
        'refresh_token' => 'expiring-token',
        'token_expires_at' => now()->addMinutes(5),
    ]);
    Http::fake([
        config('trypost.platforms.instagram.auth_api').'/refresh_access_token*' => Http::response([
            'access_token' => 'extended-token',
            'token_type' => 'bearer',
            'expires_in' => 5183944,
        ]),
        '*' => Http::response(['data' => [['name' => 'reach', 'values' => [['value' => 9]]]]]),
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => $account->platform_user_id,
    ]);

    app(InstagramPublicationMetricsCollector::class)->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC'));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/insights')
        && $request->hasHeader('Authorization', 'Bearer extended-token'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/insights')
        && str_contains($request->url(), 'access_token='));
});

test('facebook reports permission and logs the Graph code when every read is refused', function () {
    Log::spy();
    $account = SocialAccount::factory()->facebook()->createQuietly();
    Http::fake([
        rtrim((string) config('trypost.platforms.facebook.graph_api'), '/').'/page_post/insights*' => Http::response(['error' => [
            'message' => '(#100) The value must be a valid insights metric',
            'type' => 'OAuthException',
            'code' => 100,
        ]], 400),
        '*' => Http::response(['error' => [
            'message' => '(#10) This endpoint requires the pages_read_user_content permission',
            'type' => 'OAuthException',
            'code' => 10,
        ]], 403),
    ]);
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => 'page_post',
        'content_type' => PublicationContentType::Image,
    ]);

    expect(fn () => app(FacebookPublicationMetricsCollector::class)
        ->collect($publication, CarbonImmutable::parse('2026-09-23', 'UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
    Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context): bool => $message === 'analytics.facebook_read_refused'
        && str_contains((string) data_get($context, 'reason'), 'Graph code 100'));
});
