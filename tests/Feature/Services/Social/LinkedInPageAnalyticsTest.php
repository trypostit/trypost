<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\LinkedInPageAnalytics;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->socialAccount = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::LinkedInPage,
        'content_type' => ContentType::LinkedInPagePost,
        'platform_post_id' => 'urn:li:share:1234567890',
    ]);
});

test('linkedin page analytics refresh hits the configured oauth host', function () {
    $oauthApi = config('trypost.platforms.linkedin.oauth_api');
    $api = config('trypost.platforms.linkedin-page.api');

    Http::fake([
        "{$oauthApi}/oauth/v2/accessToken" => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 5184000,
        ], 200),
        "{$api}/rest/organizationalEntityShareStatistics*" => Http::response([
            'elements' => [[
                'totalShareStatistics' => [
                    'impressionCount' => 0,
                    'clickCount' => 0,
                    'likeCount' => 0,
                    'commentCount' => 0,
                    'shareCount' => 0,
                ],
            ]],
        ], 200),
    ]);

    (new LinkedInPageAnalytics)->fetchPostMetrics($this->postPlatform);

    Http::assertSent(fn ($request) => str_contains($request->url(), "{$oauthApi}/oauth/v2/accessToken"));
});

test('linkedin page post metrics read lifetime share statistics for a ugcPost', function () {
    $this->socialAccount->update(['token_expires_at' => now()->addDay()]);
    $this->postPlatform->update(['platform_post_id' => 'urn:li:ugcPost:7504988143797075969']);

    $api = config('trypost.platforms.linkedin-page.api');

    Http::preventStrayRequests();
    Http::fake([
        "{$api}/rest/organizationalEntityShareStatistics*" => Http::response([
            'elements' => [[
                'ugcPost' => 'urn:li:ugcPost:7504988143797075969',
                'totalShareStatistics' => [
                    'impressionCount' => 99,
                    'clickCount' => 14,
                    'likeCount' => 0,
                    'commentCount' => 0,
                    'shareCount' => 0,
                ],
            ]],
        ], 200),
    ]);

    expect((new LinkedInPageAnalytics)->fetchPostMetrics($this->postPlatform->fresh()))->toEqual([
        ['label' => __('analytics.metrics.impressions'), 'value' => 99],
        ['label' => __('analytics.metrics.clicks'), 'value' => 14],
        ['label' => __('analytics.metrics.likes'), 'value' => 0],
        ['label' => __('analytics.metrics.comments'), 'value' => 0],
        ['label' => __('analytics.metrics.shares'), 'value' => 0],
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'ugcPosts=List(')
        && str_contains($request->url(), rawurlencode('urn:li:ugcPost:7504988143797075969')));
});

test('linkedin page post metrics filter share URNs with the shares list', function () {
    $this->socialAccount->update(['token_expires_at' => now()->addDay()]);

    $api = config('trypost.platforms.linkedin-page.api');

    Http::preventStrayRequests();
    Http::fake([
        "{$api}/rest/organizationalEntityShareStatistics*" => Http::response([
            'elements' => [[
                'share' => 'urn:li:share:1234567890',
                'totalShareStatistics' => [
                    'impressionCount' => 10,
                    'clickCount' => 2,
                    'likeCount' => 3,
                    'commentCount' => 1,
                    'shareCount' => 0,
                ],
            ]],
        ], 200),
    ]);

    expect((new LinkedInPageAnalytics)->fetchPostMetrics($this->postPlatform->fresh()))->toEqual([
        ['label' => __('analytics.metrics.impressions'), 'value' => 10],
        ['label' => __('analytics.metrics.clicks'), 'value' => 2],
        ['label' => __('analytics.metrics.likes'), 'value' => 3],
        ['label' => __('analytics.metrics.comments'), 'value' => 1],
        ['label' => __('analytics.metrics.shares'), 'value' => 0],
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'shares=List('));
});

test('linkedin page post metrics skip a workspace page that does not own the share', function () {
    $this->socialAccount->update(['token_expires_at' => now()->addDay()]);
    $this->postPlatform->update(['social_account_id' => null]);

    SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addDay(),
        'platform_user_id' => '99920311',
    ]);

    $api = config('trypost.platforms.linkedin-page.api');

    Http::preventStrayRequests();
    Http::fake([
        "{$api}/rest/organizationalEntityShareStatistics*" => Http::sequence()
            ->push(['elements' => []], 200)
            ->push([
                'elements' => [[
                    'totalShareStatistics' => [
                        'impressionCount' => 99,
                        'clickCount' => 14,
                        'likeCount' => 0,
                        'commentCount' => 0,
                        'shareCount' => 0,
                    ],
                ]],
            ], 200),
    ]);

    expect((new LinkedInPageAnalytics)->fetchPostMetrics($this->postPlatform->fresh()))->toEqual([
        ['label' => __('analytics.metrics.impressions'), 'value' => 99],
        ['label' => __('analytics.metrics.clicks'), 'value' => 14],
        ['label' => __('analytics.metrics.likes'), 'value' => 0],
        ['label' => __('analytics.metrics.comments'), 'value' => 0],
        ['label' => __('analytics.metrics.shares'), 'value' => 0],
    ]);

    Http::assertSentCount(2);
});

test('linkedin page post metrics reuse a workspace token when the published row lost its account', function () {
    $this->socialAccount->update(['token_expires_at' => now()->addDay()]);
    $this->postPlatform->update(['social_account_id' => null]);

    $api = config('trypost.platforms.linkedin-page.api');

    Http::preventStrayRequests();
    Http::fake([
        "{$api}/rest/organizationalEntityShareStatistics*" => Http::response([
            'elements' => [[
                'totalShareStatistics' => [
                    'impressionCount' => 99,
                    'clickCount' => 14,
                    'likeCount' => 0,
                    'commentCount' => 0,
                    'shareCount' => 0,
                ],
            ]],
        ], 200),
    ]);

    expect((new LinkedInPageAnalytics)->fetchPostMetrics($this->postPlatform->fresh()))->toEqual([
        ['label' => __('analytics.metrics.impressions'), 'value' => 99],
        ['label' => __('analytics.metrics.clicks'), 'value' => 14],
        ['label' => __('analytics.metrics.likes'), 'value' => 0],
        ['label' => __('analytics.metrics.comments'), 'value' => 0],
        ['label' => __('analytics.metrics.shares'), 'value' => 0],
    ]);
});
