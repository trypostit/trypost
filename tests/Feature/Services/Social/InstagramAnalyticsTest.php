<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\InstagramAnalytics;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $this->account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_123',
    ]);
    $this->graph = config('trypost.platforms.instagram.graph_api');
});

/**
 * @param  array<int, array{name: string, value: mixed}>  $metrics
 * @return array<string, mixed>
 */
function instagramInsightsResponse(array $metrics): array
{
    return [
        'data' => array_map(fn (array $metric): array => [
            'name' => $metric['name'],
            'values' => [['value' => $metric['value']]],
        ], $metrics),
    ];
}

function instagramPostPlatform(ContentType $contentType, ?string $platformPostId = 'media_123'): PostPlatform
{
    return PostPlatform::factory()->create([
        'post_id' => test()->post->id,
        'social_account_id' => test()->account->id,
        'platform' => Platform::Instagram,
        'content_type' => $contentType,
        'platform_post_id' => $platformPostId,
    ]);
}

test('instagram analytics reads feed post metrics from the media insights edge', function () {
    Http::fake([
        "{$this->graph}/media_123/insights*" => Http::response(instagramInsightsResponse([
            ['name' => 'reach', 'value' => 90],
            ['name' => 'likes', 'value' => 7],
            ['name' => 'comments', 'value' => 2],
            ['name' => 'shares', 'value' => 1],
            ['name' => 'saved', 'value' => 4],
            ['name' => 'total_interactions', 'value' => 14],
        ])),
    ]);

    $metrics = (new InstagramAnalytics)->fetchPostMetrics(instagramPostPlatform(ContentType::InstagramFeed));

    expect($metrics)->toBe([
        ['label' => __('analytics.metrics.reach'), 'value' => 90],
        ['label' => __('analytics.metrics.likes'), 'value' => 7],
        ['label' => __('analytics.metrics.comments'), 'value' => 2],
        ['label' => __('analytics.metrics.shares'), 'value' => 1],
        ['label' => __('analytics.metrics.saves'), 'value' => 4],
        ['label' => __('analytics.metrics.interactions'), 'value' => 14],
    ]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), "{$this->graph}/media_123/insights")
        && $request['metric'] === 'reach,likes,comments,shares,saved,total_interactions');
});

test('instagram analytics reads reel metrics with views instead of retired plays', function () {
    Http::fake([
        "{$this->graph}/media_123/insights*" => Http::response(instagramInsightsResponse([
            ['name' => 'reach', 'value' => 40],
            ['name' => 'likes', 'value' => 5],
            ['name' => 'comments', 'value' => 1],
            ['name' => 'shares', 'value' => 2],
            ['name' => 'saved', 'value' => 3],
            ['name' => 'views', 'value' => 120],
        ])),
    ]);

    $metrics = (new InstagramAnalytics)->fetchPostMetrics(instagramPostPlatform(ContentType::InstagramReel));

    expect($metrics)->toBe([
        ['label' => __('analytics.metrics.reach'), 'value' => 40],
        ['label' => __('analytics.metrics.likes'), 'value' => 5],
        ['label' => __('analytics.metrics.comments'), 'value' => 1],
        ['label' => __('analytics.metrics.shares'), 'value' => 2],
        ['label' => __('analytics.metrics.saves'), 'value' => 3],
        ['label' => __('analytics.metrics.views'), 'value' => 120],
    ]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), "{$this->graph}/media_123/insights")
        && $request['metric'] === 'reach,likes,comments,shares,saved,views');
});

test('instagram analytics reads story metrics with views instead of retired impressions', function () {
    Http::fake([
        "{$this->graph}/media_123/insights*" => Http::response(instagramInsightsResponse([
            ['name' => 'reach', 'value' => 35],
            ['name' => 'views', 'value' => 50],
            ['name' => 'replies', 'value' => 2],
        ])),
    ]);

    $metrics = (new InstagramAnalytics)->fetchPostMetrics(instagramPostPlatform(ContentType::InstagramStory));

    expect($metrics)->toBe([
        ['label' => __('analytics.metrics.reach'), 'value' => 35],
        ['label' => __('analytics.metrics.views'), 'value' => 50],
        ['label' => __('analytics.metrics.replies'), 'value' => 2],
    ]);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), "{$this->graph}/media_123/insights")
        && $request['metric'] === 'reach,views,replies');
});

test('instagram analytics does not request the retired plays or impressions metrics', function (ContentType $contentType) {
    Http::fake();

    (new InstagramAnalytics)->fetchPostMetrics(instagramPostPlatform($contentType));

    Http::assertNotSent(fn ($request) => str_contains($request['metric'] ?? '', 'plays')
        || str_contains($request['metric'] ?? '', 'impressions'));
})->with([
    'feed' => ContentType::InstagramFeed,
    'reel' => ContentType::InstagramReel,
    'story' => ContentType::InstagramStory,
]);

test('instagram analytics ignores metrics it did not ask for', function () {
    Http::fake([
        "{$this->graph}/media_123/insights*" => Http::response(instagramInsightsResponse([
            ['name' => 'reach', 'value' => 12],
            ['name' => 'plays', 'value' => 99],
            ['name' => 'likes', 'value' => 1],
        ])),
    ]);

    $metrics = (new InstagramAnalytics)->fetchPostMetrics(instagramPostPlatform(ContentType::InstagramFeed));

    expect($metrics)->toBe([
        ['label' => __('analytics.metrics.reach'), 'value' => 12],
        ['label' => __('analytics.metrics.likes'), 'value' => 1],
    ]);
});

test('instagram analytics reports an api rejection as unsupported', function () {
    Http::fake([
        "{$this->graph}/media_123/insights*" => Http::response([
            'error' => ['message' => '(#100) plays is not a valid metric', 'code' => 100],
        ], 400),
    ]);

    $metrics = (new InstagramAnalytics)->fetchPostMetrics(instagramPostPlatform(ContentType::InstagramReel));

    expect($metrics)->toBe(['unsupported' => true, 'reason' => 'api_error']);
});

test('instagram analytics reports a missing platform post id as unsupported', function () {
    Http::fake();

    $metrics = (new InstagramAnalytics)->fetchPostMetrics(instagramPostPlatform(ContentType::InstagramFeed, null));

    expect($metrics)->toBe(['unsupported' => true, 'reason' => 'missing_post_id']);

    Http::assertNothingSent();
});
