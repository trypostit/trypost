<?php

declare(strict_types=1);

use App\Events\PostCreated;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Listeners\PostHog\SyncUsageOnPostCreated;
use App\Models\Account;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
});

test('listener dispatches SyncAccountUsage with the account and workspace ids', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    Bus::fake();

    (new SyncUsageOnPostCreated)->handle(new PostCreated($post));

    Bus::assertDispatched(SyncAccountUsage::class, function ($job) {
        return $job->accountId === (string) $this->account->id
            && $job->workspaceId === (string) $this->workspace->id;
    });
});

test('listener is wired to the PostCreated event via auto-discovery', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    Bus::fake();

    PostCreated::dispatch($post);

    Bus::assertDispatched(SyncAccountUsage::class);
});

test('listener does not dispatch when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    Bus::fake();

    (new SyncUsageOnPostCreated)->handle(new PostCreated($post));

    Bus::assertNotDispatched(SyncAccountUsage::class);
});
