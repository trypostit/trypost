<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Account;
use App\Models\Invite;
use App\Models\Plan;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->owner = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->owner->id]);
});

test('usage returns correct counts across the account', function () {
    Workspace::factory()->count(2)->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);
    $workspace = $this->account->workspaces()->first();
    config()->set('trypost.allow_multiple_social_accounts', true);
    SocialAccount::factory()->count(3)->create(['workspace_id' => $workspace->id]);

    User::factory()->count(2)->create(['account_id' => $this->account->id]);
    Invite::factory()->count(2)->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
    ]);

    $usage = $this->account->usage();

    expect($usage)->toBe([
        'workspaceCount' => 2,
        'socialAccountCount' => 3,
        'memberCount' => 3,
        'pendingInviteCount' => 2,
        'postCount' => 0,
        'creditsUsed' => 0,
    ]);
});

test('featureLimits returns the monthly credits allotment', function () {
    $plan = Plan::where('slug', Slug::Workspace)->first();
    $this->account->update(['plan_id' => $plan->id]);

    Workspace::factory()->count(2)->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);

    $limits = $this->account->featureLimits();

    expect($limits)->toBe([
        'monthlyCreditsLimit' => 5000,
    ]);
});

test('pendingInviteCount excludes accepted invites', function () {
    Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
    ]);
    Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'accepted_at' => now(),
    ]);

    expect($this->account->usage()['pendingInviteCount'])->toBe(1);
});

test('postCount is cached and survives new posts within the TTL', function () {
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);

    Post::factory()->count(2)->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->owner->id,
    ]);

    // First call primes the cache.
    expect($this->account->usage()['postCount'])->toBe(2);

    // A new post is created mid-window. The cached value should win.
    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->owner->id,
    ]);

    expect($this->account->usage()['postCount'])->toBe(2);

    // Forgetting the cache key returns the fresh count.
    Cache::forget(Account::postsCountCacheKey((string) $this->account->id));
    expect($this->account->usage()['postCount'])->toBe(3);
});

test('postCount returns zero without querying when account has no workspaces', function () {
    expect($this->account->usage()['postCount'])->toBe(0);

    // No cache entry should be written for the empty case.
    expect(Cache::has(Account::postsCountCacheKey((string) $this->account->id)))->toBeFalse();
});

test('postCount survives a string-typed cache value (Redis serializer quirk)', function () {
    // Laravel's RedisStore stores numeric values raw (not serialised) so they
    // can be INCRemented atomically. The side effect: an int written via
    // Cache::put comes back as a string on read. The test driver is `array`
    // which preserves type, so we seed the cache with a literal string here
    // to mimic what production sees and assert the return type stays int.
    Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);

    Cache::put(Account::postsCountCacheKey((string) $this->account->id), '42', 300);

    $usage = $this->account->usage();

    expect($usage['postCount'])->toBe(42);
    expect($usage['postCount'])->toBeInt();
});
