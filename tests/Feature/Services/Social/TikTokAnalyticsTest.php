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

test('tiktok analytics reports missing_post_id while TikTok has not reported the video id, without guessing one', function () {
    Http::fake([
        $this->api.'/post/publish/status/fetch/' => Http::response([
            'data' => [
                'status' => 'PUBLISH_COMPLETE',
                'publicaly_available_post_id' => [],
            ],
            'error' => ['code' => 'ok'],
        ]),
    ]);

    $post = tiktokPost('v_pub_url~v2-1.still-in-review');

    expect((new TikTokAnalytics)->fetchPostMetrics($post))
        ->toBe(['unsupported' => true, 'reason' => 'missing_post_id'])
        ->and($post->fresh()->platform_post_id)->toBe('v_pub_url~v2-1.still-in-review');

    Http::assertSentCount(1);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/video/list/'));
});

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
