<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Post\PostMetricsFetcher;
use App\Services\Social\XAnalytics;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'x',
    ]);
});

test('a provider exception degrades to an unsupported entry instead of failing the aggregate', function () {
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $row = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::X,
        'content_type' => ContentType::XPost,
        'status' => \App\Enums\PostPlatform\Status::Published,
        'platform_post_id' => '123',
    ]);

    $this->mock(XAnalytics::class)
        ->shouldReceive('fetchPostMetrics')
        ->andThrow(new RuntimeException('token expired mid-flight'));

    $metrics = app(PostMetricsFetcher::class)->forPlatform($row->fresh());

    expect($metrics)->toBe(['unsupported' => true, 'reason' => 'error']);
});
