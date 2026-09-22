<?php

declare(strict_types=1);

use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use App\Support\Billing\SubscriptionAnalytics;
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

    (new SyncAccountUsage((string) $this->account->id))->handle(
        app(PostHogService::class),
        app(SubscriptionAnalytics::class),
    );

    Queue::assertNothingPushed();
});

test('handle returns silently when account does not exist', function () {
    Queue::fake();

    (new SyncAccountUsage((string) Str::uuid()))->handle(
        app(PostHogService::class),
        app(SubscriptionAnalytics::class),
    );

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

    (new SyncAccountUsage((string) $this->account->id))->handle(
        app(PostHogService::class),
        app(SubscriptionAnalytics::class),
    );

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

test('handle group-identifies the workspace when workspaceId is provided', function () {
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    Queue::fake();

    (new SyncAccountUsage((string) $this->account->id, (string) $workspace->id))->handle(
        app(PostHogService::class),
        app(SubscriptionAnalytics::class),
    );

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

    (new SyncAccountUsage((string) $this->account->id))->handle(
        app(PostHogService::class),
        app(SubscriptionAnalytics::class),
    );

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

    (new SyncAccountUsage((string) $this->account->id))->handle(
        app(PostHogService::class),
        app(SubscriptionAnalytics::class),
    );

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

test('handle sends the current subscription and first month offer state on the account group', function () {
    $startedAt = now()->subWeek()->startOfSecond();
    $endsAt = now()->addWeeks(3)->startOfSecond();
    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_first_month_offer',
        'stripe_status' => 'active',
        'stripe_price' => 'price_socials_monthly',
        'stripe_started_at' => $startedAt,
        'first_month_coupon_id' => 'SOCIALS_18USD',
        'first_month_offer_ends_at' => $endsAt,
    ]);
    Queue::fake();

    (new SyncAccountUsage((string) $this->account->id))->handle(
        app(PostHogService::class),
        app(SubscriptionAnalytics::class),
    );

    Queue::assertPushed(SendEvent::class, function (SendEvent $job) use ($startedAt, $endsAt): bool {
        $properties = $job->payload['properties'];

        return $job->method === 'groupIdentify'
            && $job->payload['groupType'] === 'account'
            && $properties['subscription_status'] === 'active'
            && $properties['subscription_started_at'] === $startedAt->toIso8601String()
            && $properties['subscription_coupon_id'] === 'SOCIALS_18USD'
            && $properties['is_in_first_month_offer'] === true
            && $properties['first_month_offer_ends_at'] === $endsAt->toIso8601String();
    });
});
