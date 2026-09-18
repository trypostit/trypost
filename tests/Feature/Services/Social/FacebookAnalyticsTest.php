<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\FacebookAnalytics;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $this->account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'page_123',
    ]);
    $this->graph = config('trypost.platforms.facebook.graph_api');
});

/**
 * @param  array<int, array{name: string, value: mixed}>  $metrics
 * @return array<string, mixed>
 */
function facebookInsightsResponse(array $metrics): array
{
    return [
        'data' => array_map(fn (array $metric): array => [
            'name' => $metric['name'],
            'period' => 'lifetime',
            'values' => [['value' => $metric['value']]],
        ], $metrics),
    ];
}

function facebookPostPlatform(ContentType $contentType, string $platformPostId): PostPlatform
{
    return PostPlatform::factory()->create([
        'post_id' => test()->post->id,
        'social_account_id' => test()->account->id,
        'platform' => Platform::Facebook,
        'content_type' => $contentType,
        'platform_post_id' => $platformPostId,
    ]);
}

test('facebook analytics reads feed post metrics from the post insights edge', function () {
    Http::fake([
        "{$this->graph}/page_123_post_456/insights*" => Http::response(facebookInsightsResponse([
            ['name' => 'post_media_view', 'value' => 120],
            ['name' => 'post_total_media_view_unique', 'value' => 90],
            ['name' => 'post_reactions_like_total', 'value' => 7],
            ['name' => 'post_clicks', 'value' => 3],
        ])),
    ]);

    $metrics = (new FacebookAnalytics)->fetchPostMetrics(facebookPostPlatform(ContentType::FacebookPost, 'page_123_post_456'));

    expect($metrics)->toBe([
        ['label' => 'Impressions', 'value' => 120],
        ['label' => 'Reach', 'value' => 90],
        ['label' => 'Likes', 'value' => 7],
        ['label' => 'Clicks', 'value' => 3],
    ]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), "{$this->graph}/page_123_post_456/insights")
        && $request['metric'] === 'post_media_view,post_total_media_view_unique,post_reactions_like_total,post_clicks');
});

test('facebook analytics does not request the deprecated post_impressions metrics', function () {
    Http::fake();

    (new FacebookAnalytics)->fetchPostMetrics(facebookPostPlatform(ContentType::FacebookPost, 'page_123_post_456'));

    Http::assertNotSent(fn ($request) => str_contains($request['metric'] ?? '', 'post_impressions'));
});

test('facebook analytics reads reel metrics from the video insights edge', function () {
    Http::fake([
        "{$this->graph}/reel_video_123/video_insights*" => Http::response(facebookInsightsResponse([
            ['name' => 'total_video_impressions', 'value' => 500],
            ['name' => 'total_video_views', 'value' => 210],
            ['name' => 'total_video_reactions_by_type_total', 'value' => ['like' => 4, 'love' => 2, 'haha' => 1]],
        ])),
    ]);

    $metrics = (new FacebookAnalytics)->fetchPostMetrics(facebookPostPlatform(ContentType::FacebookReel, 'reel_video_123'));

    expect($metrics)->toBe([
        ['label' => 'Impressions', 'value' => 500],
        ['label' => 'Video Views', 'value' => 210],
        ['label' => 'Reactions', 'value' => 7],
    ]);

    Http::assertNotSent(fn ($request) => str_starts_with($request->url(), "{$this->graph}/reel_video_123/insights"));
});

test('facebook analytics reads story metrics with the story metric family', function () {
    Http::fake([
        "{$this->graph}/story_post_123/insights*" => Http::response(facebookInsightsResponse([
            ['name' => 'page_story_impressions_by_story_id', 'value' => 40],
            ['name' => 'page_story_impressions_by_story_id_unique', 'value' => 35],
            ['name' => 'story_interaction', 'value' => 6],
            ['name' => 'pages_fb_story_thread_lightweight_reactions', 'value' => 3],
            ['name' => 'pages_fb_story_replies', 'value' => 2],
            ['name' => 'pages_fb_story_shares', 'value' => 1],
        ])),
    ]);

    $metrics = (new FacebookAnalytics)->fetchPostMetrics(facebookPostPlatform(ContentType::FacebookStory, 'story_post_123'));

    expect($metrics)->toBe([
        ['label' => 'Impressions', 'value' => 40],
        ['label' => 'Reach', 'value' => 35],
        ['label' => 'Interactions', 'value' => 6],
        ['label' => 'Reactions', 'value' => 3],
        ['label' => 'Replies', 'value' => 2],
        ['label' => 'Shares', 'value' => 1],
    ]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), "{$this->graph}/story_post_123/insights")
        && ! str_contains($request['metric'], 'post_'));
});

test('facebook analytics reports an api rejection as unsupported', function () {
    Http::fake([
        "{$this->graph}/story_post_123/insights*" => Http::response([
            'error' => ['message' => '(#100) Param metric[0] must be one of {...}', 'code' => 100],
        ], 400),
    ]);

    $metrics = (new FacebookAnalytics)->fetchPostMetrics(facebookPostPlatform(ContentType::FacebookStory, 'story_post_123'));

    expect($metrics)->toBe(['unsupported' => true, 'reason' => 'api_error']);
});

test('facebook analytics reports a missing platform post id as unsupported', function () {
    Http::fake();

    $metrics = (new FacebookAnalytics)->fetchPostMetrics(PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->account->id,
        'platform' => Platform::Facebook,
        'content_type' => ContentType::FacebookPost,
        'platform_post_id' => null,
    ]));

    expect($metrics)->toBe(['unsupported' => true, 'reason' => 'missing_post_id']);

    Http::assertNothingSent();
});
