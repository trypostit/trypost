<?php

declare(strict_types=1);

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
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
        'provider_post_id' => 'record-key',
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
        'provider_post_id' => $platform === Platform::Facebook ? 'page_123456789' : '123456789',
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
    ]]], ['impressions' => 30, 'saves' => 0, 'pin_clicks' => 2]],
    'youtube' => [Platform::YouTube, [
        'columnHeaders' => [['name' => 'views'], ['name' => 'estimatedMinutesWatched'], ['name' => 'averageViewDuration'], ['name' => 'likes']],
        'rows' => [[231, 2.5, 23.4, 0]],
    ], ['views' => 231, 'watch_time_milliseconds' => 150000, 'average_watch_time_milliseconds' => 23400, 'reactions' => 0, 'engagements' => 0]],
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
        'provider_post_id' => 'video-new',
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
        ->toThrow(fn (AnalyticsCollectionException $exception): bool => $exception->category === 'rate_limited');
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
        ->toThrow(fn (AnalyticsCollectionException $exception): bool => $exception->category === 'permission');
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
        'provider_post_id' => 'v_pub_abc',
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
        ->and($publication->fresh()->provider_post_id)->toBe('123456789')
        ->and($postPlatform->fresh()->platform_post_id)->toBe('123456789');
    Http::assertSentCount(2);
});
