<?php

declare(strict_types=1);

use App\Enums\Analytics\MetricPrecision;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Followers\FollowerCollectorFactory;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
});

test('included platforms collect follower totals from their canonical read paths', function () {
    Http::fake(function (Request $request) {
        $url = $request->url();

        return match (true) {
            str_contains($url, 'graph.instagram.com'),
            str_contains($url, 'graph.facebook.com') => Http::response(['followers_count' => 123]),
            str_contains($url, 'graph.threads.net') => Http::response(['data' => [[
                'name' => 'followers_count', 'total_value' => ['value' => 123],
            ]]]),
            str_contains($url, 'api.x.com/2/users/') => Http::response(['data' => ['public_metrics' => ['followers_count' => 123]]]),
            str_contains($url, 'api.pinterest.com/v5/user_account') => Http::response(['follower_count' => 123]),
            str_contains($url, 'googleapis.com/youtube/v3/channels') => Http::response(['items' => [[
                'statistics' => ['subscriberCount' => '123', 'hiddenSubscriberCount' => false],
            ]]]),
            str_contains($url, 'open.tiktokapis.com/v2/user/info') => Http::response(['data' => ['user' => ['follower_count' => 123]]]),
            str_contains($url, 'public.api.bsky.app/xrpc/app.bsky.actor.getProfile') => Http::response(['followersCount' => 123]),
            str_contains($url, 'mastodon.example/api/v1/accounts/') => Http::response(['followers_count' => 123]),
            default => Http::response([], 404),
        };
    });

    $factory = app(FollowerCollectorFactory::class);
    $date = CarbonImmutable::parse('2026-09-23', 'UTC');
    $platforms = [
        Platform::Instagram,
        Platform::InstagramFacebook,
        Platform::Facebook,
        Platform::Threads,
        Platform::X,
        Platform::Pinterest,
        Platform::YouTube,
        Platform::TikTok,
        Platform::Bluesky,
        Platform::Mastodon,
    ];

    foreach ($platforms as $platform) {
        $account = SocialAccount::factory()->create([
            'platform' => $platform,
            'platform_user_id' => "provider-{$platform->value}",
            'meta' => $platform === Platform::Mastodon ? ['instance' => 'https://mastodon.example'] : [],
        ]);

        $observation = $factory->for($platform)->collect($account, $date);

        expect($observation->followers)->toBe(123)
            ->and($observation->date->equalTo($date))->toBeTrue()
            ->and($observation->precision)->toBe(
                $platform === Platform::YouTube
                    ? MetricPrecision::Approximate
                    : MetricPrecision::Exact,
            );
    }

    Http::assertSentCount(10);
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/2/users/provider-x')
        && $request['user.fields'] === 'public_metrics');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/youtube/v3/channels')
        && $request['part'] === 'statistics'
        && $request['id'] === 'provider-youtube');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v2/user/info/')
        && $request['fields'] === 'follower_count');
});

test('Meta follower failures use Graph error codes even when HTTP status is 400', function (int $code, string $category) {
    Http::fake(['*' => Http::response(['error' => ['code' => $code]], 400)]);
    $account = SocialAccount::factory()->instagram()->create();

    expect(fn () => app(FollowerCollectorFactory::class)->for(Platform::Instagram)
        ->collect($account, CarbonImmutable::today('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception): bool => $exception->category === $category);
})->with([
    [190, 'authentication'],
    [10, 'permission'],
    [200, 'permission'],
    [80002, 'rate_limited'],
]);

test('YouTube follower quota failures are rate limited rather than permission failures', function (string $reason) {
    Http::fake(['*' => Http::response(['error' => ['errors' => [['reason' => $reason]]]], 403)]);
    $account = SocialAccount::factory()->youtube()->create();

    expect(fn () => app(FollowerCollectorFactory::class)->for(Platform::YouTube)
        ->collect($account, CarbonImmutable::today('UTC')))
        ->toThrow(fn (AnalyticsCollectionException $exception): bool => $exception->category === 'rate_limited');
})->with(['quotaExceeded', 'rateLimitExceeded', 'userRateLimitExceeded']);

test('missing follower fields are unavailable rather than measured zero', function () {
    Http::fake(['*' => Http::response(['data' => ['public_metrics' => []]])]);
    $account = SocialAccount::factory()->x()->create();

    expect(fn () => app(FollowerCollectorFactory::class)
        ->for(Platform::X)
        ->collect($account, CarbonImmutable::parse('2026-09-23', 'UTC')))
        ->toThrow(AnalyticsCollectionException::class, 'missing follower metric');
});

test('a hidden youtube subscriber total is persisted as unavailable null', function () {
    Http::fake(['*' => Http::response(['items' => [[
        'statistics' => ['hiddenSubscriberCount' => true],
    ]]])]);
    $account = SocialAccount::factory()->youtube()->create();

    $observation = app(FollowerCollectorFactory::class)
        ->for(Platform::YouTube)
        ->collect($account, CarbonImmutable::parse('2026-09-23', 'UTC'));

    expect($observation->followers)->toBeNull()
        ->and($observation->precision)->toBe(MetricPrecision::Approximate);
});

test('excluded platforms have no collector and make no provider request', function (Platform $platform) {
    Http::preventStrayRequests();

    expect(fn () => app(FollowerCollectorFactory::class)->for($platform))
        ->toThrow(AnalyticsCollectionException::class);

    Http::assertNothingSent();
})->with([
    Platform::LinkedIn,
    Platform::LinkedInPage,
    Platform::Telegram,
    Platform::Discord,
    Platform::GoogleBusiness,
]);
