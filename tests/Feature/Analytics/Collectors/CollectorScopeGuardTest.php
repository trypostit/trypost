<?php

declare(strict_types=1);

use App\Enums\Analytics\MetricKey;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use App\Services\Analytics\Collectors\Metrics\PublicationMetricsCollectorFactory;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Support\ChannelPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
    CarbonImmutable::setTestNow('2026-10-06 12:00:00 UTC');
});

function scopeGuardGrant(Platform $platform): array
{
    return match ($platform) {
        Platform::X => ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access'],
        Platform::Facebook => ['public_profile', 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights', 'business_management'],
        Platform::Instagram => ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights'],
        Platform::InstagramFacebook => ['public_profile', 'pages_show_list', 'pages_read_engagement', 'business_management', 'instagram_basic', 'instagram_content_publish', 'instagram_manage_insights'],
        Platform::Threads => ['threads_basic', 'threads_content_publish', 'threads_manage_insights'],
        Platform::TikTok => ['user.info.basic', 'user.info.profile', 'user.info.stats', 'video.publish', 'video.upload', 'video.list'],
        Platform::Pinterest => ['boards:read', 'boards:write', 'pins:read', 'pins:write', 'user_accounts:read'],
        Platform::YouTube => [
            'https://www.googleapis.com/auth/youtube.upload',
            'https://www.googleapis.com/auth/youtube.readonly',
            'https://www.googleapis.com/auth/youtube.force-ssl',
            'https://www.googleapis.com/auth/yt-analytics.readonly',
        ],
    };
}

function scopeGuardAccount(Platform $platform, ?array $scopes): SocialAccount
{
    return SocialAccount::factory()->create([
        'platform' => $platform,
        'scopes' => $scopes ?? ChannelPermissions::missingFrom([], $platform, GatedFeature::Publish),
        'token_expires_at' => now()->addDays(30),
    ]);
}

function scopeGuardPublication(SocialAccount $account): AnalyticsPublication
{
    $publication = AnalyticsPublication::factory()->create([
        'workspace_id' => $account->workspace_id,
        'social_account_id' => $account->id,
        'social_account_key' => $account->id,
        'platform' => $account->platform,
        'network' => $account->platform->network(),
        'platform_user_id' => $account->platform_user_id,
        'remote_id' => match ($account->platform) {
            Platform::Facebook => "{$account->platform_user_id}_post",
            Platform::TikTok => '7300000000000000001',
            default => 'remote-1',
        },
        'content_type' => PublicationContentType::Image,
        'provider_published_at' => CarbonImmutable::now('UTC')->subDays(2),
    ]);

    return $publication->setRelation('socialAccount', $account);
}

function scopeGuardRequested(string $path): bool
{
    return Http::recorded(fn (Request $request): bool => str_contains((string) parse_url($request->url(), PHP_URL_PATH), $path))->isNotEmpty();
}

dataset('collector scope guard platforms', [
    'x' => [Platform::X],
    'facebook' => [Platform::Facebook],
    'instagram' => [Platform::Instagram],
    'instagram via facebook' => [Platform::InstagramFacebook],
    'threads' => [Platform::Threads],
    'tiktok' => [Platform::TikTok],
    'pinterest' => [Platform::Pinterest],
    'youtube' => [Platform::YouTube],
]);

dataset('publication history grants', [
    'x' => [Platform::X, ['tweet.read', 'users.read']],
    'facebook' => [Platform::Facebook, ['pages_read_engagement']],
    'instagram' => [Platform::Instagram, ['instagram_business_basic']],
    'instagram via facebook' => [Platform::InstagramFacebook, ['instagram_basic']],
    'threads' => [Platform::Threads, ['threads_basic']],
    'tiktok' => [Platform::TikTok, ['video.list']],
    'pinterest' => [Platform::Pinterest, ['pins:read', 'boards:read']],
    'youtube' => [Platform::YouTube, ['https://www.googleapis.com/auth/youtube.readonly']],
]);

dataset('publication metric grants', [
    'x' => [Platform::X, ['tweet.read', 'users.read'], MetricKey::Impressions],
    'facebook' => [Platform::Facebook, ['pages_read_engagement', 'read_insights'], MetricKey::Impressions],
    'instagram' => [Platform::Instagram, ['instagram_business_basic', 'instagram_business_manage_insights'], MetricKey::Reach],
    'instagram via facebook' => [Platform::InstagramFacebook, ['instagram_basic', 'instagram_manage_insights', 'pages_read_engagement'], MetricKey::Reach],
    'threads' => [Platform::Threads, ['threads_basic', 'threads_manage_insights'], MetricKey::Views],
    'tiktok' => [Platform::TikTok, ['video.list'], MetricKey::Views],
    'pinterest' => [Platform::Pinterest, ['pins:read', 'boards:read'], MetricKey::Impressions],
    'youtube' => [Platform::YouTube, ['https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/yt-analytics.readonly'], MetricKey::Shares],
]);

dataset('follower grants', [
    'x' => [Platform::X, ['tweet.read', 'users.read']],
    'facebook' => [Platform::Facebook, ['pages_read_engagement']],
    'instagram' => [Platform::Instagram, ['instagram_business_basic']],
    'instagram via facebook' => [Platform::InstagramFacebook, ['instagram_basic', 'pages_read_engagement']],
    'threads' => [Platform::Threads, ['threads_basic', 'threads_manage_insights']],
    'tiktok' => [Platform::TikTok, ['user.info.stats']],
    'pinterest' => [Platform::Pinterest, ['user_accounts:read']],
    'youtube' => [Platform::YouTube, ['https://www.googleapis.com/auth/youtube.readonly']],
]);

dataset('collector grant breadth', [
    'feature grant' => [false],
    'full grant' => [true],
]);

test('a publication history read the grant does not cover makes no request and ends provider limited', function (Platform $platform) {
    Http::fake();
    $account = scopeGuardAccount($platform, null);

    $page = app(PublicationHistoryCollectorFactory::class)->for($account)->page($account, null, CarbonImmutable::parse('2026-01-01', 'UTC'));

    expect($page->publications)->toBe([])
        ->and($page->nextCursor)->toBeNull()
        ->and($page->providerExhausted)->toBeTrue()
        ->and($page->providerLimited)->toBeTrue();
    Http::assertNothingSent();
})->with('collector scope guard platforms');

test('a publication metrics read the grant does not cover makes no request and is refused as permission', function (Platform $platform) {
    Http::fake();
    $publication = scopeGuardPublication(scopeGuardAccount($platform, null));

    expect(fn () => app(PublicationMetricsCollectorFactory::class)->for($platform)->collect($publication, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission')
            ->and($exception->getMessage())->toContain('scope'));
    Http::assertNothingSent();
})->with('collector scope guard platforms');

test('a follower read the grant does not cover makes no request and is refused as permission', function (Platform $platform) {
    Http::fake();
    $account = scopeGuardAccount($platform, null);

    expect(fn () => app(FollowerCollectorFactory::class)->for($platform)->collect($account, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
    Http::assertNothingSent();
})->with('collector scope guard platforms');

test('an account stored before grants were recorded is not known to lack a scope and still collects', function () {
    Http::fake(['*' => Http::response(['data' => ['public_metrics' => ['followers_count' => 12]]])]);
    $account = scopeGuardAccount(Platform::X, []);

    $observation = app(FollowerCollectorFactory::class)->for(Platform::X)->collect($account, CarbonImmutable::now('UTC'));

    expect($observation->followers)->toBe(12);
});

test('youtube keeps the data api counts and skips the analytics report without the analytics scope', function () {
    Http::fake(['*' => Http::response(['items' => [['id' => 'remote-1', 'statistics' => ['viewCount' => '40']]]])]);
    $grant = array_values(array_diff(scopeGuardGrant(Platform::YouTube), ['https://www.googleapis.com/auth/yt-analytics.readonly']));
    $publication = scopeGuardPublication(scopeGuardAccount(Platform::YouTube, $grant));

    $observation = app(PublicationMetricsCollectorFactory::class)->for(Platform::YouTube)->collect($publication, CarbonImmutable::now('UTC'));

    expect(collect($observation->metrics)->firstWhere('key', MetricKey::Views)?->value)->toBe(40)
        ->and(scopeGuardRequested('/reports'))->toBeFalse();
});

test('facebook keeps the post counts and skips page insights without read_insights', function () {
    Http::fake(['*' => Http::response(['reactions' => ['summary' => ['total_count' => 5]], 'shares' => ['count' => 2]])]);
    $grant = array_values(array_diff(scopeGuardGrant(Platform::Facebook), ['read_insights']));
    $publication = scopeGuardPublication(scopeGuardAccount(Platform::Facebook, $grant));

    $observation = app(PublicationMetricsCollectorFactory::class)->for(Platform::Facebook)->collect($publication, CarbonImmutable::now('UTC'));

    expect(collect($observation->metrics)->firstWhere('key', MetricKey::Reactions)?->value)->toBe(5)
        ->and(scopeGuardRequested('/insights'))->toBeFalse();
});

function scopeGuardApi(Platform $platform): string
{
    return match ($platform) {
        Platform::Facebook, Platform::Instagram, Platform::InstagramFacebook, Platform::Threads => config("trypost.platforms.{$platform->value}.graph_api"),
        Platform::Pinterest, Platform::TikTok, Platform::X => config("trypost.platforms.{$platform->value}.api"),
        Platform::YouTube => config('trypost.platforms.youtube.data_api'),
    };
}

function fakeScopeGuardReads(array $responses): void
{
    Http::preventStrayRequests()->fake(collect($responses)
        ->mapWithKeys(fn ($response, string $url): array => [$url => $response, "{$url}?*" => $response])
        ->all());
}

function assertScopeGuardReads(array $urls, string $method = 'GET'): void
{
    Http::assertSentInOrder(array_map(
        fn (string $url): Closure => fn (Request $request): bool => $request->method() === $method
            && explode('?', $request->url(), 2)[0] === $url,
        $urls,
    ));
}

test('publication history returns network publications with a valid grant', function (Platform $platform, array $scopes, bool $fullGrant) {
    $account = scopeGuardAccount($platform, $fullGrant ? scopeGuardGrant($platform) : $scopes);
    $api = scopeGuardApi($platform);
    $accountId = $account->platform_user_id;
    $postId = match ($platform) {
        Platform::Facebook => "{$accountId}_7300000000000000001",
        Platform::X, Platform::Instagram, Platform::InstagramFacebook, Platform::Threads, Platform::TikTok, Platform::Pinterest => '7300000000000000001',
        Platform::YouTube => 'video-1',
    };
    $publishedAt = '2026-09-20T12:00:00Z';
    $responses = match ($platform) {
        Platform::X => ["{$api}/users/{$accountId}/tweets" => Http::response([
            'data' => [['id' => $postId, 'created_at' => $publishedAt, 'text' => 'A post']],
            'meta' => ['result_count' => 1],
        ])],
        Platform::Facebook => ["{$api}/{$accountId}/published_posts" => Http::response([
            'data' => [['id' => $postId, 'created_time' => $publishedAt, 'message' => 'A post']],
        ])],
        Platform::Instagram, Platform::InstagramFacebook => [
            "{$api}/{$accountId}/media" => Http::response([
                'data' => [['id' => $postId, 'media_type' => 'IMAGE', 'timestamp' => $publishedAt]],
            ]),
            "{$api}/{$accountId}/stories" => Http::response(['data' => []]),
        ],
        Platform::Threads => ["{$api}/{$accountId}/threads" => Http::response([
            'data' => [['id' => $postId, 'media_type' => 'TEXT_POST', 'timestamp' => $publishedAt, 'text' => 'A post']],
        ])],
        Platform::TikTok => ["{$api}/video/list/" => Http::response([
            'data' => ['videos' => [['id' => $postId, 'create_time' => 1_789_905_600]], 'has_more' => false],
            'error' => ['code' => 'ok'],
        ])],
        Platform::Pinterest => ["{$api}/pins" => Http::response([
            'items' => [['id' => $postId, 'created_at' => $publishedAt, 'media' => ['media_type' => 'image']]],
        ])],
        Platform::YouTube => [
            "{$api}/channels" => Http::response(['items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'uploads-1']]]]]),
            "{$api}/playlistItems" => Http::response(['items' => [['contentDetails' => ['videoId' => $postId]]]]),
            "{$api}/videos" => Http::response(['items' => [['id' => $postId, 'snippet' => ['publishedAt' => $publishedAt]]]]),
        ],
    };
    fakeScopeGuardReads($responses);

    $page = app(PublicationHistoryCollectorFactory::class)->for($account)->page($account, null, CarbonImmutable::parse('2026-01-01', 'UTC'));

    expect(array_column($page->publications, 'providerPostId'))->toBe([$postId])
        ->and($page->providerExhausted)->toBeTrue()
        ->and($page->providerLimited)->toBeFalse();
    assertScopeGuardReads(array_keys($responses), $platform === Platform::TikTok ? 'POST' : 'GET');
})->with('publication history grants', 'collector grant breadth');

test('publication metrics return measured values with a valid grant', function (Platform $platform, array $scopes, MetricKey $metric, bool $fullGrant) {
    $publication = scopeGuardPublication(scopeGuardAccount($platform, $fullGrant ? scopeGuardGrant($platform) : $scopes));
    $api = scopeGuardApi($platform);
    $postId = $publication->remote_id;
    $analyticsApi = config('trypost.platforms.youtube.analytics_api');
    $responses = match ($platform) {
        Platform::X => ["{$api}/tweets/{$postId}" => Http::response([
            'data' => ['id' => $postId, 'public_metrics' => ['impression_count' => 42]],
        ])],
        Platform::Facebook => [
            "{$api}/{$postId}/insights" => Http::response(['data' => [['name' => 'post_media_view', 'values' => [['value' => 42]]]]]),
            "{$api}/{$postId}" => Http::sequence()
                ->push(['reactions' => ['summary' => ['total_count' => 5]], 'shares' => ['count' => 2]])
                ->push(['comments' => ['summary' => ['total_count' => 3]]]),
        ],
        Platform::Instagram, Platform::InstagramFacebook => ["{$api}/{$postId}/insights" => Http::sequence()
            ->push(['data' => [['name' => 'reach', 'values' => [['value' => 42]]]]])
            ->push(['data' => [['name' => 'total_interactions', 'total_value' => ['value' => 3]]]])],
        Platform::Threads => ["{$api}/{$postId}/insights" => Http::response([
            'data' => [['name' => 'views', 'total_value' => ['value' => 42]]],
        ])],
        Platform::TikTok => ["{$api}/video/query/" => Http::response([
            'data' => ['videos' => [['id' => $postId, 'view_count' => 42]]],
            'error' => ['code' => 'ok'],
        ])],
        Platform::Pinterest => ["{$api}/pins/{$postId}/analytics" => Http::response([
            'all' => ['summary_metrics' => ['IMPRESSION' => 42]],
        ])],
        Platform::YouTube => [
            "{$api}/videos" => Http::response(['items' => [['id' => $postId, 'statistics' => ['viewCount' => '120']]]]),
            "{$analyticsApi}/reports" => Http::response(['columnHeaders' => [['name' => 'shares']], 'rows' => [[42]]]),
        ],
    };
    fakeScopeGuardReads($responses);

    $observation = app(PublicationMetricsCollectorFactory::class)->for($platform)->collect($publication, CarbonImmutable::now('UTC'));

    expect(collect($observation->metrics)->firstWhere('key', $metric)?->value)->toBe(42);
    $urls = match ($platform) {
        Platform::Facebook => [...array_keys($responses), "{$api}/{$postId}"],
        Platform::Instagram, Platform::InstagramFacebook => [...array_keys($responses), "{$api}/{$postId}/insights"],
        Platform::X, Platform::Threads, Platform::TikTok, Platform::Pinterest, Platform::YouTube => array_keys($responses),
    };
    assertScopeGuardReads($urls, $platform === Platform::TikTok ? 'POST' : 'GET');
})->with('publication metric grants', 'collector grant breadth');

test('follower collection returns the network count with a valid grant', function (Platform $platform, array $scopes, bool $fullGrant) {
    $account = scopeGuardAccount($platform, $fullGrant ? scopeGuardGrant($platform) : $scopes);
    $api = scopeGuardApi($platform);
    $accountId = $account->platform_user_id;
    $responses = match ($platform) {
        Platform::X => ["{$api}/users/{$accountId}" => Http::response(['data' => ['public_metrics' => ['followers_count' => 42]]])],
        Platform::Facebook, Platform::Instagram, Platform::InstagramFacebook => ["{$api}/{$accountId}" => Http::response(['followers_count' => 42])],
        Platform::Threads => ["{$api}/{$accountId}/threads_insights" => Http::response([
            'data' => [['name' => 'followers_count', 'total_value' => ['value' => 42]]],
        ])],
        Platform::TikTok => ["{$api}/user/info/" => Http::response(['data' => ['user' => ['follower_count' => 42]], 'error' => ['code' => 'ok']])],
        Platform::Pinterest => ["{$api}/user_account" => Http::response(['follower_count' => 42])],
        Platform::YouTube => ["{$api}/channels" => Http::response(['items' => [['statistics' => ['subscriberCount' => '42']]]])],
    };
    fakeScopeGuardReads($responses);

    $observation = app(FollowerCollectorFactory::class)->for($platform)->collect($account, CarbonImmutable::now('UTC'));

    expect($observation->followers)->toBe(42);
    assertScopeGuardReads(array_keys($responses));
})->with('follower grants', 'collector grant breadth');

dataset('networks whose history scopes are a subset of their insights scopes', [
    'instagram' => [Platform::Instagram, ['instagram_business_basic']],
    'instagram via facebook' => [Platform::InstagramFacebook, ['instagram_basic']],
    'threads' => [Platform::Threads, ['threads_basic']],
]);

test('a publication metrics read is refused on the post history scopes alone', function (Platform $platform, array $scopes) {
    Http::fake();
    $publication = scopeGuardPublication(scopeGuardAccount($platform, $scopes));

    expect(fn () => app(PublicationMetricsCollectorFactory::class)->for($platform)->collect($publication, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
    Http::assertNothingSent();
})->with('networks whose history scopes are a subset of their insights scopes');

test('a follower read is refused on the post history scopes alone', function (Platform $platform, array $scopes) {
    Http::fake();
    $account = scopeGuardAccount($platform, $scopes);

    expect(fn () => app(FollowerCollectorFactory::class)->for($platform)->collect($account, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
    Http::assertNothingSent();
})->with([
    'instagram via facebook' => [Platform::InstagramFacebook, ['instagram_basic']],
    'threads' => [Platform::Threads, ['threads_basic']],
]);

test('an insights read is skipped without a request when the connect does not ask for its scopes', function () {
    Http::fake();
    config(['trypost.platforms.threads.scopes' => ['threads_basic', 'threads_content_publish']]);
    $publication = scopeGuardPublication(scopeGuardAccount(Platform::Threads, scopeGuardGrant(Platform::Threads)));

    expect(fn () => app(PublicationMetricsCollectorFactory::class)->for(Platform::Threads)->collect($publication, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
    Http::assertNothingSent();
});

test('a publication history read sends nothing when the connect does not ask for its scopes, even with a full grant', function (Platform $platform, array $requested) {
    Http::fake(['*' => Http::response([])]);
    config(["trypost.platforms.{$platform->value}.scopes" => $requested]);
    $account = scopeGuardAccount($platform, scopeGuardGrant($platform));

    $page = app(PublicationHistoryCollectorFactory::class)->for($account)->page($account, null, now('UTC')->subMonths(3)->startOfMonth());

    expect($page->publications)->toBeEmpty()
        ->and($page->providerExhausted)->toBeTrue();
    Http::assertNothingSent();
})->with([
    'tiktok' => [Platform::TikTok, ['user.info.basic', 'video.publish', 'video.upload']],
    'pinterest' => [Platform::Pinterest, ['boards:read', 'boards:write', 'pins:write', 'user_accounts:read']],
]);

test('an optional insights read sends nothing when the connect does not ask for its scope, while the rest of the read goes on', function (Platform $platform, string $path, array $requested, MetricKey $metric) {
    Http::fake([
        '*/videos*' => Http::response(['items' => [['id' => 'remote-1', 'statistics' => ['viewCount' => '10']]]]),
        '*' => Http::response(['reactions' => ['summary' => ['total_count' => 10]]]),
    ]);
    config(["trypost.platforms.{$platform->value}.scopes" => $requested]);
    $publication = scopeGuardPublication(scopeGuardAccount($platform, scopeGuardGrant($platform)));

    $observation = app(PublicationMetricsCollectorFactory::class)->for($platform)->collect($publication, CarbonImmutable::now('UTC'));

    expect(collect($observation->metrics)->firstWhere('key', $metric)?->value)->toBe(10)
        ->and(scopeGuardRequested($path))->toBeFalse();
})->with([
    'facebook without read_insights' => [Platform::Facebook, '/insights', ['public_profile', 'pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'business_management'], MetricKey::Reactions],
    'youtube without analytics' => [Platform::YouTube, '/reports', ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/youtube.force-ssl'], MetricKey::Views],
]);
