<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\LinkedInAnalytics;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->socialAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addDay(),
    ]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::LinkedIn,
        'content_type' => ContentType::LinkedInPost,
        'status' => Status::Published,
        'platform_post_id' => 'urn:li:share:7503082467755646976',
    ]);
    $this->api = config('trypost.platforms.linkedin.api');
});

test('linkedin member post metrics read likes and comments from v2 socialActions', function () {
    Http::preventStrayRequests();
    Http::fake([
        "{$this->api}/v2/socialActions/*" => Http::response([
            'likesSummary' => ['totalLikes' => 1],
            'commentsSummary' => ['aggregatedTotalComments' => 0],
        ], 200),
    ]);

    expect((new LinkedInAnalytics)->fetchPostMetrics($this->postPlatform))->toEqual([
        ['label' => __('analytics.metrics.likes'), 'value' => 1],
        ['label' => __('analytics.metrics.comments'), 'value' => 0],
    ]);

    Http::assertSent(fn ($request) => str_contains(
        $request->url(),
        "{$this->api}/v2/socialActions/".urlencode('urn:li:share:7503082467755646976'),
    ));
});

test('linkedin member post metrics refresh an expired token against the configured oauth host', function () {
    $this->socialAccount->update([
        'token_expires_at' => now()->subHour(),
        'refresh_token' => 'old_refresh_token',
    ]);

    $oauthApi = config('trypost.platforms.linkedin.oauth_api');

    Http::preventStrayRequests();
    Http::fake([
        "{$oauthApi}/oauth/v2/accessToken" => Http::response([
            'access_token' => 'new_token',
            'refresh_token' => 'new_refresh_token',
            'expires_in' => 5184000,
        ], 200),
        "{$this->api}/v2/socialActions/*" => Http::response([
            'likesSummary' => ['totalLikes' => 4],
            'commentsSummary' => ['aggregatedTotalComments' => 2],
        ], 200),
    ]);

    expect((new LinkedInAnalytics)->fetchPostMetrics($this->postPlatform->fresh()))->toEqual([
        ['label' => __('analytics.metrics.likes'), 'value' => 4],
        ['label' => __('analytics.metrics.comments'), 'value' => 2],
    ]);

    Http::assertSent(fn ($request) => str_contains($request->url(), "{$oauthApi}/oauth/v2/accessToken"));
});

test('linkedin member post metrics return unsupported when the share id is missing', function () {
    $this->postPlatform->update(['platform_post_id' => null]);

    expect((new LinkedInAnalytics)->fetchPostMetrics($this->postPlatform->fresh()))
        ->toBe(['unsupported' => true, 'reason' => 'missing_post_id']);
});

test('linkedin member post metrics reuse a workspace token when the published row lost its account', function () {
    $this->postPlatform->update(['social_account_id' => null]);

    Http::preventStrayRequests();
    Http::fake([
        "{$this->api}/v2/socialActions/*" => Http::response([
            'likesSummary' => ['totalLikes' => 12],
            'commentsSummary' => ['aggregatedTotalComments' => 1],
        ], 200),
    ]);

    expect((new LinkedInAnalytics)->fetchPostMetrics($this->postPlatform->fresh()))->toEqual([
        ['label' => __('analytics.metrics.likes'), 'value' => 12],
        ['label' => __('analytics.metrics.comments'), 'value' => 1],
    ]);
});

test('linkedin member post metrics return unsupported when socialActions fails', function () {
    Http::preventStrayRequests();
    Http::fake([
        "{$this->api}/v2/socialActions/*" => Http::response(['message' => 'ACCESS_DENIED'], 403),
    ]);

    expect((new LinkedInAnalytics)->fetchPostMetrics($this->postPlatform))
        ->toBe(['unsupported' => true, 'reason' => 'api_error']);
});
