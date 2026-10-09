<?php

declare(strict_types=1);

use App\Actions\Analytics\ReadPublicationAnalytics;
use App\Enums\Post\PublishStatus;
use App\Enums\TikTok\PrivacyLevel;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\TikTokAnalytics;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'tiktoker',
        'token_expires_at' => now()->addDays(1),
    ]);
    $this->post = Post::factory()->forAccount($this->account)->create([
        'user_id' => $this->user->id,
    ]);
    $this->api = config('trypost.platforms.tiktok.api');
});

/**
 * @return array<string, mixed>
 */
function tiktokVideoQueryResponse(string $videoId, array $counts = []): array
{
    return [
        'data' => [
            'videos' => [[
                'id' => $videoId,
                'view_count' => $counts['view_count'] ?? 0,
                'like_count' => $counts['like_count'] ?? 0,
                'comment_count' => $counts['comment_count'] ?? 0,
                'share_count' => $counts['share_count'] ?? 0,
            ]],
        ],
        'error' => ['code' => 'ok'],
    ];
}

function tiktokPost(?string $platformPostId = '7685359243088103444'): Post
{
    return tap(test()->post->forceFill([
        'platform_post_id' => $platformPostId,
        'platform_url' => 'https://www.tiktok.com/@tiktoker',
        'meta' => ['privacy_level' => PrivacyLevel::PublicToEveryone->value],
    ]))->save();
}

test('tiktok analytics reads post metrics from video query', function () {
    $videoId = '7685359243088103444';

    Http::fake([
        $this->api.'/video/query/*' => Http::response(tiktokVideoQueryResponse($videoId, [
            'view_count' => 1200,
            'like_count' => 45,
            'comment_count' => 8,
            'share_count' => 3,
        ])),
    ]);

    $metrics = (new TikTokAnalytics)->fetchPostMetrics(tiktokPost($videoId));

    expect($metrics)->toBe([
        ['label' => __('analytics.metrics.views'), 'value' => 1200],
        ['label' => __('analytics.metrics.likes'), 'value' => 45],
        ['label' => __('analytics.metrics.comments'), 'value' => 8],
        ['label' => __('analytics.metrics.shares'), 'value' => 3],
    ]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), "{$this->api}/video/query/")
        && data_get($request->data(), 'filters.video_ids') === [$videoId]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/post/publish/status/fetch/'));
});

test('tiktok analytics resolves a publish id then persists the public video id', function () {
    $publishId = 'v_pub_url~v2-1.7685359243088103444';
    $videoId = '7685359243088103444';
    $post = tiktokPost($publishId);

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => [$videoId],
            ],
            'error' => ['code' => 'ok'],
        ]),
        $this->api.'/video/query/*' => Http::response(tiktokVideoQueryResponse($videoId, [
            'view_count' => 90,
            'like_count' => 4,
            'comment_count' => 1,
            'share_count' => 0,
        ])),
    ]);

    $metrics = (new TikTokAnalytics)->fetchPostMetrics($post);

    expect($metrics)->toBe([
        ['label' => __('analytics.metrics.views'), 'value' => 90],
        ['label' => __('analytics.metrics.likes'), 'value' => 4],
        ['label' => __('analytics.metrics.comments'), 'value' => 1],
        ['label' => __('analytics.metrics.shares'), 'value' => 0],
    ]);

    $post->refresh();

    expect($post->platform_post_id)->toBe($videoId)
        ->and($post->platform_url)->toBe('https://www.tiktok.com/@tiktoker/video/7685359243088103444');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/post/publish/status/fetch/')
        && $request['publish_id'] === $publishId);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), "{$this->api}/video/query/")
        && data_get($request->data(), 'filters.video_ids') === [$videoId]);
});

test('tiktok analytics reports missing_post_id when neither status nor the video list resolve the publish id', function () {
    $this->post->update(['content' => 'Still in review']);

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => [],
            ],
            'error' => ['code' => 'ok'],
        ]),
        $this->api.'/video/list/*' => Http::response([
            'data' => ['videos' => [], 'has_more' => false],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $metrics = (new TikTokAnalytics)->fetchPostMetrics(
        tiktokPost('v_pub_url~v2-1.still-in-review')
    );

    expect($metrics)->toBe(['unsupported' => true, 'reason' => 'missing_post_id']);

    Http::assertSentCount(2);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/video/query/'));
});

test('tiktok analytics never matches an untitled video from the list', function () {
    $this->post->update(['content' => 'A caption that no listed video carries']);

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []],
            'error' => ['code' => 'ok'],
        ]),
        $this->api.'/video/list/*' => Http::response([
            'data' => [
                'videos' => [['id' => '7000000000000000001', 'title' => '']],
                'has_more' => false,
            ],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $post = tiktokPost('v_pub_url~v2-1.untitled');

    expect((new TikTokAnalytics)->fetchPostMetrics($post))
        ->toBe(['unsupported' => true, 'reason' => 'missing_post_id'])
        ->and($post->fresh()->platform_post_id)->toBe('v_pub_url~v2-1.untitled');
});

test('tiktok analytics does not scan the video list for a self only post', function () {
    $this->post->update(['content' => 'Private caption']);

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $post = tiktokPost('v_pub_url~v2-1.private');
    $post->update(['meta' => ['privacy_level' => PrivacyLevel::SelfOnly->value]]);

    expect((new TikTokAnalytics)->fetchPostMetrics($post))
        ->toBe(['unsupported' => true, 'reason' => 'missing_post_id']);

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/video/list/'));
});

test('tiktok analytics matches a publish id to the public video by caption', function () {
    $this->post->update([
        'content' => 'Eu bato nessa tecla há 7 anos: construam produtos globais.',
    ]);

    $videoId = '7682891910226234644';
    $post = tiktokPost('v_pub_url~v2-1.7682889326782842900');

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => [],
            ],
            'error' => ['code' => 'ok'],
        ]),
        $this->api.'/video/list/*' => Http::response([
            'data' => [
                'videos' => [[
                    'id' => $videoId,
                    'title' => 'Eu bato nessa tecla há 7 anos: construam produtos globais.',
                    'create_time' => now()->getTimestamp(),
                ]],
                'has_more' => false,
            ],
            'error' => ['code' => 'ok'],
        ]),
        $this->api.'/video/query/*' => Http::response(tiktokVideoQueryResponse($videoId, [
            'view_count' => 661,
            'like_count' => 13,
            'comment_count' => 2,
            'share_count' => 1,
        ])),
    ]);

    $metrics = (new TikTokAnalytics)->fetchPostMetrics($post);

    expect($metrics)->toBe([
        ['label' => __('analytics.metrics.views'), 'value' => 661],
        ['label' => __('analytics.metrics.likes'), 'value' => 13],
        ['label' => __('analytics.metrics.comments'), 'value' => 2],
        ['label' => __('analytics.metrics.shares'), 'value' => 1],
    ]);

    $post->refresh();

    expect($post->platform_post_id)->toBe($videoId)
        ->and($post->platform_url)->toBe("https://www.tiktok.com/@tiktoker/video/{$videoId}");
});

test('tiktok analytics stops scanning at videos older than the publish instead of claiming a same-caption repost', function () {
    $this->post->update(['content' => 'Same caption, posted twice']);

    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => []],
            'error' => ['code' => 'ok'],
        ]),
        $this->api.'/video/list/*' => Http::response([
            'data' => [
                'videos' => [[
                    'id' => '7000000000000000002',
                    'title' => 'Same caption, posted twice',
                    'create_time' => now()->subDays(3)->getTimestamp(),
                ]],
                'has_more' => true,
                'cursor' => now()->subDays(3)->getTimestampMs(),
            ],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $post = tiktokPost('v_pub_url~v2-1.repost');
    $post->update(['published_at' => now()]);

    expect((new TikTokAnalytics)->fetchPostMetrics($post))
        ->toBe(['unsupported' => true, 'reason' => 'missing_post_id'])
        ->and($post->fresh()->platform_post_id)->toBe('v_pub_url~v2-1.repost');

    Http::assertSentCount(2);
});

test('tiktok video matching keeps the one-day cutoff inclusive without changing the published timestamp', function (int $secondsBeforeCutoff, ?string $expectedVideoId) {
    $this->post->update(['content' => 'Boundary caption']);
    $publishedAt = CarbonImmutable::parse('2026-09-23 12:00:00', 'UTC');
    $videoId = '7000000000000000003';
    $post = tiktokPost('v_pub_url~v2-1.boundary');
    $post->update(['published_at' => $publishedAt]);

    Http::fake([
        $this->api.'/video/list/*' => Http::response([
            'data' => [
                'videos' => [[
                    'id' => $videoId,
                    'title' => 'Boundary caption',
                    'create_time' => $publishedAt->subDay()->subSeconds($secondsBeforeCutoff)->getTimestamp(),
                ]],
                'has_more' => false,
            ],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    expect((new TikTokAnalytics)->findVideoIdByCaption($post))->toBe($expectedVideoId)
        ->and($post->published_at->toDateTimeString())->toBe('2026-09-23 12:00:00');
})->with([
    'at cutoff' => [0, '7000000000000000003'],
    'before cutoff' => [1, null],
]);

test('tiktok post metrics facade returns the saved video url and metrics without provider reads', function () {
    $videoId = '7685359243088103444';
    $post = tiktokPost($videoId);
    $post->forceFill([
        'publish_status' => PublishStatus::Published,
        'platform_url' => "https://www.tiktok.com/@tiktoker/video/{$videoId}",
    ])->save();
    $publication = AnalyticsPublication::query()->where('post_id', $post->id)->firstOrFail();
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $publication->id,
        'views_count' => 5,
        'metrics' => ['views' => ['value' => 5, 'unit' => 'count', 'availability' => 'available']],
    ]);

    Http::fake();

    $analytics = app(ReadPublicationAnalytics::class)->forPost($this->post->fresh());

    expect($analytics)->toMatchArray([
        'platform_post_id' => $videoId,
        'platform_url' => "https://www.tiktok.com/@tiktoker/video/{$videoId}",
    ])->and($analytics['metrics']['metrics']['views']['value'])->toBe(5);

    Http::assertNothingSent();
});

test('tiktok analytics reports a missing platform post id as unsupported', function () {
    Http::fake();

    $metrics = (new TikTokAnalytics)->fetchPostMetrics(tiktokPost(null));

    expect($metrics)->toBe(['unsupported' => true, 'reason' => 'missing_post_id']);

    Http::assertNothingSent();
});

test('tiktok analytics reports a query rejection as unsupported', function () {
    Http::fake([
        $this->api.'/video/query/*' => Http::response([
            'error' => ['code' => 'access_token_invalid', 'message' => 'The access token is invalid or not found in the request.'],
        ], 401),
    ]);

    $metrics = (new TikTokAnalytics)->fetchPostMetrics(tiktokPost());

    expect($metrics)->toBe(['unsupported' => true, 'reason' => 'api_error']);
});

test('tiktok analytics reports an empty video query as unsupported', function () {
    Http::fake([
        $this->api.'/video/query/*' => Http::response([
            'data' => ['videos' => []],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $metrics = (new TikTokAnalytics)->fetchPostMetrics(tiktokPost());

    expect($metrics)->toBe(['unsupported' => true, 'reason' => 'api_error']);
});
