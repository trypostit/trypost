<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
});

test('confirmed publication queues an account activity sync', function () {
    $postPlatform = PostPlatform::factory()->recycle($this->post)->create();

    Queue::fake();

    $postPlatform->markAsPublished('remote-post-id');

    Queue::assertPushed(
        SyncAccountUsage::class,
        fn (SyncAccountUsage $job): bool => $job->accountId === (string) $this->account->id
            && $job->workspaceId === null,
    );
});

test('non-published status changes do not queue an account activity sync', function () {
    $postPlatform = PostPlatform::factory()->recycle($this->post)->create();

    Queue::fake();

    $postPlatform->update(['status' => Status::Publishing]);

    Queue::assertNotPushed(SyncAccountUsage::class);
});

test('account activity sync is not queued when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    $postPlatform = PostPlatform::factory()->recycle($this->post)->create();

    Queue::fake();

    $postPlatform->markAsPublished('remote-post-id');

    Queue::assertNotPushed(SyncAccountUsage::class);
});
