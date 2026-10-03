<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status;
use App\Jobs\Analytics\SyncTryPostPublication;
use App\Jobs\PostHog\SyncAccountPublishingActivity;
use App\Models\Account;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
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
    $this->freezeTime();
    $postPlatform = PostPlatform::factory()->recycle($this->post)->create();

    Queue::fake();

    $postPlatform->markAsPublished('remote-post-id');

    Queue::assertPushed(
        SyncAccountPublishingActivity::class,
        fn (SyncAccountPublishingActivity $job): bool => $job->accountId === (string) $this->account->id
            && $job->delay?->equalTo(now()->addSeconds(SyncAccountPublishingActivity::DEBOUNCE_SECONDS))
            && $job->afterCommit === true,
    );
});

test('non-published status changes do not queue an account activity sync', function () {
    $postPlatform = PostPlatform::factory()->recycle($this->post)->create();

    Queue::fake();

    $postPlatform->update(['status' => Status::Publishing]);

    Queue::assertNotPushed(SyncAccountPublishingActivity::class);
});

test('account activity sync is not queued when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Queue::fake();
    $socialAccount = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $postPlatform = PostPlatform::factory()->x()->recycle($this->post)->create([
        'social_account_id' => $socialAccount->id,
    ]);

    $postPlatform->markAsPublished('remote-post-id');

    Queue::assertNotPushed(SyncAccountPublishingActivity::class);
    Queue::assertPushed(SyncTryPostPublication::class);
});

test('excluded platform does not queue an analytics publication sync', function () {
    config(['services.posthog.enabled' => false]);
    $postPlatform = PostPlatform::factory()->linkedin()->recycle($this->post)->create();
    Queue::fake();

    $postPlatform->markAsPublished('remote-post-id');

    Queue::assertNotPushed(SyncTryPostPublication::class);
});

test('updating an already published platform does not queue another sync', function () {
    $postPlatform = PostPlatform::factory()->published()->recycle($this->post)->create();
    Queue::fake();

    $postPlatform->update(['platform_url' => 'https://example.com/published-post']);

    Queue::assertNotPushed(SyncAccountPublishingActivity::class);
});
