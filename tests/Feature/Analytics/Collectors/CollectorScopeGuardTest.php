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
use Illuminate\Support\Arr;
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
        'scopes' => $scopes ?? ChannelPermissions::scopes($platform, GatedFeature::Publish),
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

dataset('publication scope guards', [
    'x' => [Platform::X, '/tweets'],
    'facebook' => [Platform::Facebook, '/published_posts'],
    'instagram' => [Platform::Instagram, '/media'],
    'instagram via facebook' => [Platform::InstagramFacebook, '/media'],
    'threads' => [Platform::Threads, '/threads'],
    'tiktok' => [Platform::TikTok, '/video/list/'],
    'pinterest' => [Platform::Pinterest, '/pins'],
    'youtube' => [Platform::YouTube, '/channels'],
]);

dataset('metric scope guards', [
    'x' => [Platform::X, '/tweets/'],
    'facebook' => [Platform::Facebook, '/insights'],
    'instagram' => [Platform::Instagram, '/insights'],
    'instagram via facebook' => [Platform::InstagramFacebook, '/insights'],
    'threads' => [Platform::Threads, '/insights'],
    'tiktok' => [Platform::TikTok, '/video/query/'],
    'pinterest' => [Platform::Pinterest, '/analytics'],
    'youtube' => [Platform::YouTube, '/videos'],
]);

dataset('follower scope guards', [
    'x' => [Platform::X, '/users/'],
    'facebook' => [Platform::Facebook, '/'],
    'instagram' => [Platform::Instagram, '/'],
    'instagram via facebook' => [Platform::InstagramFacebook, '/'],
    'threads' => [Platform::Threads, '/threads_insights'],
    'tiktok' => [Platform::TikTok, '/user/info/'],
    'pinterest' => [Platform::Pinterest, '/user_account'],
    'youtube' => [Platform::YouTube, '/channels'],
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
})->with('publication scope guards');

test('a publication history read the grant covers calls the network', function (Platform $platform, string $path) {
    Http::fake(['*' => Http::response([])]);
    $account = scopeGuardAccount($platform, scopeGuardGrant($platform));

    rescue(fn () => app(PublicationHistoryCollectorFactory::class)->for($account)->page($account, null, CarbonImmutable::parse('2026-01-01', 'UTC')), report: false);

    expect(scopeGuardRequested($path))->toBeTrue();
})->with('publication scope guards');

test('a publication metrics read the grant does not cover makes no request and is refused as permission', function (Platform $platform) {
    Http::fake();
    $publication = scopeGuardPublication(scopeGuardAccount($platform, null));

    expect(fn () => app(PublicationMetricsCollectorFactory::class)->for($platform)->collect($publication, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission')
            ->and($exception->getMessage())->toContain('scope'));
    Http::assertNothingSent();
})->with('metric scope guards');

test('a publication metrics read the grant covers calls the network', function (Platform $platform, string $path) {
    Http::fake(['*' => Http::response([])]);
    $publication = scopeGuardPublication(scopeGuardAccount($platform, scopeGuardGrant($platform)));

    rescue(fn () => app(PublicationMetricsCollectorFactory::class)->for($platform)->collect($publication, CarbonImmutable::now('UTC')), report: false);

    expect(scopeGuardRequested($path))->toBeTrue();
})->with('metric scope guards');

test('a follower read the grant does not cover makes no request and is refused as permission', function (Platform $platform) {
    Http::fake();
    $account = scopeGuardAccount($platform, null);

    expect(fn () => app(FollowerCollectorFactory::class)->for($platform)->collect($account, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
    Http::assertNothingSent();
})->with('follower scope guards');

test('a follower read the grant covers calls the network', function (Platform $platform, string $path) {
    Http::fake(['*' => Http::response([])]);
    $account = scopeGuardAccount($platform, scopeGuardGrant($platform));

    rescue(fn () => app(FollowerCollectorFactory::class)->for($platform)->collect($account, CarbonImmutable::now('UTC')), report: false);

    expect(scopeGuardRequested($path))->toBeTrue();
})->with('follower scope guards');

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

/**
 * A grant holding the publish scopes and exactly the scopes of the given
 * features, so a collector reading another feature's scopes would refuse it.
 *
 * @return list<string>
 */
function scopeGuardGrantFor(Platform $platform, GatedFeature ...$features): array
{
    return collect([GatedFeature::Publish, ...$features])
        ->flatMap(fn (GatedFeature $granted): array => Arr::flatten(ChannelPermissions::scopes($platform, $granted)))
        ->unique()
        ->values()
        ->all();
}

test('a publication history read needs only the post history scopes', function (Platform $platform, string $path) {
    Http::fake(['*' => Http::response([])]);
    $account = scopeGuardAccount($platform, scopeGuardGrantFor($platform, GatedFeature::PostHistory));

    rescue(fn () => app(PublicationHistoryCollectorFactory::class)->for($account)->page($account, null, CarbonImmutable::parse('2026-01-01', 'UTC')), report: false);

    expect(scopeGuardRequested($path))->toBeTrue();
})->with('publication scope guards');

test('a publication metrics read needs only the post insights scopes', function (Platform $platform, string $path) {
    Http::fake(['*' => Http::response([])]);
    $publication = scopeGuardPublication(scopeGuardAccount($platform, scopeGuardGrantFor($platform, GatedFeature::PostInsights, GatedFeature::PostInsightsExtended)));

    rescue(fn () => app(PublicationMetricsCollectorFactory::class)->for($platform)->collect($publication, CarbonImmutable::now('UTC')), report: false);

    expect(scopeGuardRequested($path))->toBeTrue();
})->with('metric scope guards');

test('a follower read needs only the follower scopes', function (Platform $platform, string $path) {
    Http::fake(['*' => Http::response([])]);
    $account = scopeGuardAccount($platform, scopeGuardGrantFor($platform, GatedFeature::Followers));

    rescue(fn () => app(FollowerCollectorFactory::class)->for($platform)->collect($account, CarbonImmutable::now('UTC')), report: false);

    expect(scopeGuardRequested($path))->toBeTrue();
})->with('follower scope guards');

dataset('networks whose history scopes are a subset of their insights scopes', [
    'instagram' => [Platform::Instagram],
    'instagram via facebook' => [Platform::InstagramFacebook],
    'threads' => [Platform::Threads],
]);

test('a publication metrics read is refused on the post history scopes alone', function (Platform $platform) {
    Http::fake();
    $publication = scopeGuardPublication(scopeGuardAccount($platform, scopeGuardGrantFor($platform, GatedFeature::PostHistory)));

    expect(fn () => app(PublicationMetricsCollectorFactory::class)->for($platform)->collect($publication, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
    Http::assertNothingSent();
})->with('networks whose history scopes are a subset of their insights scopes');

test('a follower read is refused on the post history scopes alone', function (Platform $platform) {
    Http::fake();
    $account = scopeGuardAccount($platform, scopeGuardGrantFor($platform, GatedFeature::PostHistory));

    expect(fn () => app(FollowerCollectorFactory::class)->for($platform)->collect($account, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
    Http::assertNothingSent();
})->with(['instagram via facebook' => [Platform::InstagramFacebook], 'threads' => [Platform::Threads]]);

test('an insights read is skipped without a request when the connect does not ask for its scopes', function () {
    Http::fake();
    config(['trypost.platforms.threads.scopes' => ['threads_basic', 'threads_content_publish']]);
    $publication = scopeGuardPublication(scopeGuardAccount(Platform::Threads, scopeGuardGrant(Platform::Threads)));

    expect(fn () => app(PublicationMetricsCollectorFactory::class)->for(Platform::Threads)->collect($publication, CarbonImmutable::now('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception) => expect($exception->category)->toBe('permission'));
    Http::assertNothingSent();
});
