<?php

declare(strict_types=1);

use App\Enums\Analytics\MetricTimeBasis;
use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\PinterestPublicationCollector;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Services\Analytics\Collectors\Publications\TikTokPublicationCollector;
use App\Services\Analytics\Collectors\Publications\XPublicationCollector;
use App\Services\Analytics\Collectors\Publications\YouTubePublicationCollector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
});

test('x reads one owned timeline page with only discovery fields', function () {
    Http::fake(['*' => Http::response([
        'data' => [[
            'id' => 'tweet-1',
            'text' => 'A paid read',
            'created_at' => '2026-09-20T12:00:00.000Z',
            'attachments' => ['media_keys' => ['media-1']],
        ]],
        'includes' => ['media' => [[
            'media_key' => 'media-1',
            'type' => 'photo',
            'url' => 'https://cdn.example/tweet.jpg',
        ]]],
        'meta' => ['next_token' => 'x-next'],
    ])]);
    $account = SocialAccount::factory()->create([
        'platform' => Platform::X,
        'username' => 'example',
    ]);

    $page = app(XPublicationCollector::class)->page(
        $account,
        'x-cursor',
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->publications)->toHaveCount(1)
        ->and($page->publications[0]->contentType)->toBe(PublicationContentType::Image)
        ->and($page->publications[0]->permalink)->toBe('https://x.com/example/status/tweet-1')
        ->and($page->nextCursor)->toBe('x-next')
        ->and($page->providerExhausted)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/users/{$account->platform_user_id}/tweets")
        && $request['pagination_token'] === 'x-cursor'
        && $request['tweet.fields'] === 'created_at,attachments'
        && ! str_contains((string) $request['tweet.fields'], 'public_metrics'));
});

test('pinterest reads one pin page and records lifetime metric semantics', function () {
    Http::fake(['*' => Http::response([
        'items' => [[
            'id' => 'pin-1',
            'created_at' => '2026-09-20T12:00:00Z',
            'title' => 'A pin',
            'description' => 'Pin description',
            'media' => [
                'media_type' => 'video',
                'images' => ['600x' => ['url' => 'https://cdn.example/pin.jpg']],
            ],
        ]],
        'bookmark' => 'pin-next',
    ])]);
    $account = SocialAccount::factory()->create(['platform' => Platform::Pinterest]);

    $page = app(PinterestPublicationCollector::class)->page(
        $account,
        'pin-cursor',
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->publications)->toHaveCount(1)
        ->and($page->publications[0]->contentType)->toBe(PublicationContentType::Video)
        ->and($page->publications[0]->providerMetadata)->toMatchArray([
            'metric_time_basis' => MetricTimeBasis::Lifetime->value,
        ])
        ->and($page->nextCursor)->toBe('pin-next');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/v5/pins')
        && $request['bookmark'] === 'pin-cursor');
});

test('youtube pages the uploads playlist and hydrates videos in one batch without inferring shorts', function () {
    $api = config('trypost.platforms.youtube.data_api');
    Http::fake([
        "{$api}/channels*" => Http::response([
            'items' => [['contentDetails' => ['relatedPlaylists' => ['uploads' => 'uploads-1']]]],
        ]),
        "{$api}/playlistItems*" => Http::response([
            'items' => [
                ['contentDetails' => ['videoId' => 'video-1']],
                ['contentDetails' => ['videoId' => 'video-2']],
            ],
            'nextPageToken' => 'youtube-next',
        ]),
        "{$api}/videos*" => Http::response([
            'items' => [
                [
                    'id' => 'video-1',
                    'snippet' => [
                        'title' => 'Short-shaped upload',
                        'description' => 'Still not authoritatively a Short',
                        'publishedAt' => '2026-09-20T12:00:00Z',
                        'thumbnails' => ['medium' => ['url' => 'https://cdn.example/video-1.jpg']],
                    ],
                    'contentDetails' => ['duration' => 'PT20S'],
                ],
                [
                    'id' => 'video-2',
                    'snippet' => [
                        'title' => 'Long upload',
                        'publishedAt' => '2026-09-19T12:00:00Z',
                    ],
                    'contentDetails' => ['duration' => 'PT20M'],
                ],
            ],
        ]),
    ]);
    $account = SocialAccount::factory()->create(['platform' => Platform::YouTube]);

    $page = app(YouTubePublicationCollector::class)->page(
        $account,
        'youtube-cursor',
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->publications)->toHaveCount(2)
        ->and(array_column($page->publications, 'contentType'))->toBe([
            PublicationContentType::Video,
            PublicationContentType::Video,
        ])
        ->and($page->nextCursor)->toBe('youtube-next');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/playlistItems')
        && $request['playlistId'] === 'uploads-1'
        && $request['pageToken'] === 'youtube-cursor');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/videos')
        && $request['id'] === 'video-1,video-2');
});

test('tiktok reads at most twenty videos and marks expiring covers', function () {
    Http::fake(['*' => Http::response(['data' => [
        'videos' => [[
            'id' => 'video-1',
            'title' => 'A TikTok',
            'create_time' => 1_790_000_000,
            'cover_image_url' => 'https://cdn.example/cover.jpg?x-expires=1790003600',
            'share_url' => 'https://www.tiktok.com/@example/video/video-1',
        ]],
        'cursor' => 1_790_000_000_000,
        'has_more' => true,
    ]])]);
    $account = SocialAccount::factory()->create([
        'platform' => Platform::TikTok,
        'scopes' => ['video.list'],
    ]);

    $page = app(TikTokPublicationCollector::class)->page(
        $account,
        '1789000000000',
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->publications)->toHaveCount(1)
        ->and($page->publications[0]->contentType)->toBe(PublicationContentType::Video)
        ->and($page->publications[0]->previewMetadata)->toMatchArray([
            'thumbnail_url' => 'https://cdn.example/cover.jpg?x-expires=1790003600',
            'expires_at' => CarbonImmutable::createFromTimestampUTC(1_790_003_600)->toIso8601String(),
        ])
        ->and($page->nextCursor)->toBe('1790000000000');
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/video/list/')
        && $request['max_count'] === 20
        && $request['cursor'] === 1_789_000_000_000);
});

test('tiktok reports provider limited coverage when video list scope is missing', function () {
    Http::fake();
    $account = SocialAccount::factory()->create([
        'platform' => Platform::TikTok,
        'scopes' => ['video.publish'],
    ]);

    $page = app(TikTokPublicationCollector::class)->page(
        $account,
        null,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->publications)->toBe([])
        ->and($page->nextCursor)->toBeNull()
        ->and($page->providerExhausted)->toBeTrue()
        ->and($page->providerLimited)->toBeTrue();
    Http::assertNothingSent();
});

test('publication collector factory supports media networks', function (Platform $platform, string $collector) {
    $account = SocialAccount::factory()->create(['platform' => $platform]);

    expect(app(PublicationHistoryCollectorFactory::class)->for($account))->toBeInstanceOf($collector);
})->with([
    [Platform::X, XPublicationCollector::class],
    [Platform::Pinterest, PinterestPublicationCollector::class],
    [Platform::YouTube, YouTubePublicationCollector::class],
    [Platform::TikTok, TikTokPublicationCollector::class],
]);
