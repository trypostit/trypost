<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\SocialAccount;
use App\Services\Analytics\Collectors\Publications\FacebookPublicationCollector;
use App\Services\Analytics\Collectors\Publications\InstagramPublicationCollector;
use App\Services\Analytics\Collectors\Publications\PublicationHistoryCollectorFactory;
use App\Services\Analytics\Collectors\Publications\ThreadsPublicationCollector;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Bus::fake();
});

test('Meta history classifies revoked tokens from Graph error codes on HTTP 400', function () {
    Http::fake(['*' => Http::response(['error' => ['code' => 190]], 400)]);
    $account = SocialAccount::factory()->instagram()->create();

    expect(fn () => app(InstagramPublicationCollector::class)
        ->page($account, null, CarbonImmutable::today('UTC')->subYear()))
        ->toThrow(fn (AnalyticsCollectionException $exception): bool => $exception->category === 'authentication');
});

test('instagram reads one owned media page without claiming expired stories', function () {
    Http::fake([
        '*' => Http::response([
            'data' => [
                ['id' => 'feed-1', 'media_type' => 'IMAGE', 'media_product_type' => 'FEED', 'caption' => 'Feed', 'permalink' => 'https://instagram.com/p/feed', 'timestamp' => '2026-09-20T12:00:00+0000', 'media_url' => 'https://cdn.example/feed.jpg'],
                ['id' => 'carousel-1', 'media_type' => 'CAROUSEL_ALBUM', 'media_product_type' => 'FEED', 'caption' => 'Carousel', 'permalink' => 'https://instagram.com/p/carousel', 'timestamp' => '2026-09-19T12:00:00+0000'],
                ['id' => 'reel-1', 'media_type' => 'VIDEO', 'media_product_type' => 'REELS', 'caption' => 'Reel', 'permalink' => 'https://instagram.com/reel/1', 'timestamp' => '2026-09-18T12:00:00+0000', 'thumbnail_url' => 'https://cdn.example/reel.jpg'],
            ],
            'paging' => ['cursors' => ['after' => 'cursor-2'], 'next' => 'https://graph.instagram.com/next'],
        ]),
    ]);
    $account = SocialAccount::factory()->instagram()->create();

    $page = app(InstagramPublicationCollector::class)->page(
        $account,
        'cursor-1',
        CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'),
    );

    expect($page->publications)->toHaveCount(3)
        ->and(array_column($page->publications, 'contentType'))->toBe([
            PublicationContentType::Image,
            PublicationContentType::Carousel,
            PublicationContentType::Reel,
        ])
        ->and($page->nextCursor)->toBe('cursor-2')
        ->and($page->providerExhausted)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/{$account->platform_user_id}/media")
        && ! str_contains($request->url(), '/stories')
        && $request['after'] === 'cursor-1');
});

test('instagram stops a page at the cutoff and omits an older provider row', function () {
    Http::fake(['*' => Http::response([
        'data' => [
            ['id' => 'at-cutoff', 'media_type' => 'IMAGE', 'timestamp' => '2026-09-18T12:00:00+0000'],
            ['id' => 'too-old', 'media_type' => 'IMAGE', 'timestamp' => '2026-09-18T11:59:59+0000'],
        ],
        'paging' => ['cursors' => ['after' => 'ignored'], 'next' => 'https://graph.instagram.com/next'],
    ])]);
    $account = SocialAccount::factory()->instagram()->create();

    $page = app(InstagramPublicationCollector::class)->page(
        $account,
        null,
        CarbonImmutable::parse('2026-09-18 12:00:00', 'UTC'),
    );

    expect($page->publications)->toHaveCount(1)
        ->and($page->publications[0]->providerPostId)->toBe('at-cutoff')
        ->and($page->nextCursor)->toBeNull()
        ->and($page->providerExhausted)->toBeTrue();
});

test('facebook reads page-owned published posts and keeps a video when preview hydration fails', function () {
    $graph = config('trypost.platforms.facebook.graph_api');
    Http::fake([
        "{$graph}/*/published_posts*" => Http::response([
            'data' => [
                [
                    'id' => 'page_video',
                    'message' => 'Video post',
                    'created_time' => '2026-09-20T12:00:00+0000',
                    'permalink_url' => 'https://facebook.com/page/videos/123',
                    'attachments' => ['data' => [[
                        'media_type' => 'video',
                        'target' => ['id' => 'video-123'],
                    ]]],
                ],
                [
                    'id' => 'page_photo',
                    'message' => 'Photo post',
                    'created_time' => '2026-09-19T12:00:00+0000',
                    'permalink_url' => 'https://facebook.com/page/posts/456',
                    'attachments' => ['data' => [[
                        'media_type' => 'photo',
                        'media' => ['image' => ['src' => 'https://cdn.example/photo.jpg']],
                    ]]],
                ],
            ],
        ]),
        "{$graph}/video-123*" => Http::response(['error' => ['code' => 2]], 500),
    ]);
    $account = SocialAccount::factory()->facebook()->create();

    $page = app(FacebookPublicationCollector::class)->page(
        $account,
        null,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->publications)->toHaveCount(2)
        ->and($page->publications[0]->providerPostId)->toBe('page_video')
        ->and($page->publications[0]->contentType)->toBe(PublicationContentType::Video)
        ->and($page->publications[0]->previewMetadata)->toBeNull()
        ->and($page->publications[1]->contentType)->toBe(PublicationContentType::Image)
        ->and($page->providerExhausted)->toBeTrue();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/published_posts'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/feed'));
});

test('threads reads one owned post page and returns its cursor', function () {
    Http::fake(['*' => Http::response([
        'data' => [[
            'id' => 'thread-1',
            'media_type' => 'VIDEO',
            'text' => 'A thread',
            'permalink' => 'https://threads.net/@example/post/1',
            'timestamp' => '2026-09-20T12:00:00+0000',
            'thumbnail_url' => 'https://cdn.example/thread.jpg',
        ]],
        'paging' => ['cursors' => ['after' => 'thread-cursor'], 'next' => 'https://graph.threads.net/next'],
    ])]);
    $account = SocialAccount::factory()->threads()->create();

    $page = app(ThreadsPublicationCollector::class)->page(
        $account,
        null,
        CarbonImmutable::parse('2026-01-01', 'UTC'),
    );

    expect($page->publications)->toHaveCount(1)
        ->and($page->publications[0]->contentType)->toBe(PublicationContentType::Video)
        ->and($page->nextCursor)->toBe('thread-cursor')
        ->and($page->providerExhausted)->toBeFalse();
    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), "/{$account->platform_user_id}/threads"));
});

test('publication collector factory distinguishes supported Meta platforms', function (Platform $platform, string $collector) {
    $account = SocialAccount::factory()->create(['platform' => $platform]);

    expect(app(PublicationHistoryCollectorFactory::class)->for($account))->toBeInstanceOf($collector);
})->with([
    [Platform::Instagram, InstagramPublicationCollector::class],
    [Platform::InstagramFacebook, InstagramPublicationCollector::class],
    [Platform::Facebook, FacebookPublicationCollector::class],
    [Platform::Threads, ThreadsPublicationCollector::class],
]);
