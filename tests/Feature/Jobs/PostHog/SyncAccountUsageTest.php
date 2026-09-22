<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create([
        'plan_id' => Plan::query()->where('slug', 'workspace')->first()?->id,
    ]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
});

test('handle is a no-op when api key is unset', function () {
    config(['services.posthog.api_key' => null]);
    Queue::fake();

    (new SyncAccountUsage((string) $this->account->id))->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle returns silently when account does not exist', function () {
    Queue::fake();

    (new SyncAccountUsage((string) Str::uuid()))->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle group-identifies the account with usage metrics', function () {
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::factory()->count(2)->create(['workspace_id' => $workspace->id]);
    Post::factory()->count(3)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->user->id,
    ]);

    Queue::fake();

    (new SyncAccountUsage((string) $this->account->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) {
        if ($job->method !== 'groupIdentify' || $job->payload['groupType'] !== 'account') {
            return false;
        }

        $props = $job->payload['properties'];

        return $job->payload['groupKey'] === (string) $this->account->id
            && $props['workspaces_count'] === 1
            && $props['social_accounts_count'] === 2
            && $props['posts_count'] === 3;
    });
});

test('handle identifies the account with its latest confirmed publishing activity', function () {
    $olderWorkspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $latestWorkspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $olderPost = Post::factory()->create([
        'workspace_id' => $olderWorkspace->id,
        'user_id' => $this->user->id,
    ]);
    $latestPost = Post::factory()->create([
        'workspace_id' => $latestWorkspace->id,
        'user_id' => $this->user->id,
    ]);
    PostPlatform::factory()->published()->recycle($olderPost)->create([
        'platform' => Platform::X,
        'published_at' => now()->subDay(),
    ]);
    $latestPublication = PostPlatform::factory()->published()->recycle($latestPost)->create([
        'platform' => Platform::LinkedInPage,
        'published_at' => now()->subHour(),
    ]);
    PostPlatform::factory()->published()->create([
        'published_at' => now(),
    ]);

    Queue::fake();

    (new SyncAccountUsage((string) $this->account->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) use ($latestPublication) {
        if ($job->method !== 'groupIdentify' || $job->payload['groupType'] !== 'account') {
            return false;
        }

        $properties = $job->payload['properties'];

        return $properties['last_post_published_at'] === $latestPublication->published_at->toIso8601String()
            && $properties['last_post_published_network'] === 'linkedin'
            && $properties['last_published_post_id'] === $latestPublication->post_id;
    });
});

test('handle ignores posts that were not confirmed as published', function () {
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $post = Post::factory()->scheduled()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->user->id,
    ]);
    PostPlatform::factory()->recycle($post)->create([
        'status' => PostPlatformStatus::PendingReview,
    ]);

    Queue::fake();

    (new SyncAccountUsage((string) $this->account->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) {
        if ($job->method !== 'groupIdentify' || $job->payload['groupType'] !== 'account') {
            return false;
        }

        $properties = $job->payload['properties'];

        return $properties['last_post_published_at'] === null
            && $properties['last_post_published_network'] === null
            && $properties['last_published_post_id'] === null;
    });
});

test('handle group-identifies the workspace when workspaceId is provided', function () {
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    Queue::fake();

    (new SyncAccountUsage((string) $this->account->id, (string) $workspace->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) use ($workspace) {
        return $job->method === 'groupIdentify'
            && $job->payload['groupType'] === 'workspace'
            && $job->payload['groupKey'] === (string) $workspace->id
            && $job->payload['properties']['account_id'] === (string) $this->account->id
            && $job->payload['properties']['social_accounts_count'] === 1;
    });
});

test('handle skips workspace group identify when workspaceId is null', function () {
    Queue::fake();

    (new SyncAccountUsage((string) $this->account->id))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class, function ($job) {
        return $job->method === 'groupIdentify' && $job->payload['groupType'] === 'workspace';
    });
});

test('handle invalidates the posts_count cache before reading usage', function () {
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);

    Cache::put(Account::postsCountCacheKey((string) $this->account->id), 999, 300);

    Post::factory()->count(2)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->user->id,
    ]);

    Queue::fake();

    (new SyncAccountUsage((string) $this->account->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) {
        return $job->method === 'groupIdentify'
            && $job->payload['groupType'] === 'account'
            && $job->payload['properties']['posts_count'] === 2;
    });
});

test('job is queued on the posthog connection queue', function () {
    $job = new SyncAccountUsage((string) $this->account->id);

    expect($job->queue)->toBe('posthog');
});
