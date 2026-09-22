<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PostHog\SyncAccountPublishingActivity;
use App\Models\Account;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
});

test('handle sends the latest confirmed account publication directly to PostHog', function () {
    $job = new SyncAccountPublishingActivity((string) $this->account->id);
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
    PostPlatform::factory()->published()->create(['published_at' => now()]);
    PostPlatform::factory()->recycle($latestPost)->create([
        'status' => Status::PendingReview,
        'published_at' => now()->addHour(),
    ]);
    PostPlatform::factory()->recycle($latestPost)->create([
        'status' => Status::Published,
        'published_at' => null,
    ]);

    $postHog = Mockery::mock(PostHogService::class);
    $postHog->shouldReceive('groupIdentifyNow')
        ->once()
        ->withArgs(function (string $groupType, string $groupKey, array $properties) use ($latestPublication): bool {
            return $groupType === 'account'
                && $groupKey === (string) $this->account->id
                && $properties['last_post_published_at'] === $latestPublication->published_at->toIso8601String()
                && $properties['last_post_published_network'] === 'linkedin'
                && $properties['last_published_post_id'] === $latestPublication->post_id;
        });

    $job->handle($postHog);
});

test('handle clears publishing activity when the account has no confirmed publication', function () {
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->user->id,
    ]);
    PostPlatform::factory()->recycle($post)->create(['status' => Status::PendingReview]);

    $postHog = Mockery::mock(PostHogService::class);
    $postHog->shouldReceive('groupIdentifyNow')
        ->once()
        ->with('account', (string) $this->account->id, [
            'last_post_published_at' => null,
            'last_post_published_network' => null,
            'last_published_post_id' => null,
        ]);

    (new SyncAccountPublishingActivity((string) $this->account->id))->handle($postHog);
});

test('handle uses microsecond precision to select the last published network', function () {
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $this->user->id,
    ]);
    $second = now()->startOfSecond();

    PostPlatform::factory()->published()->recycle($post)->create([
        'id' => 'ffffffff-ffff-4fff-afff-ffffffffffff',
        'platform' => Platform::X,
        'published_at' => $second->copy()->addMicroseconds(100),
        'created_at' => $second,
        'updated_at' => $second,
    ]);
    $latestPublication = PostPlatform::factory()->published()->recycle($post)->create([
        'id' => '00000000-0000-4000-a000-000000000000',
        'platform' => Platform::LinkedInPage,
        'published_at' => $second->copy()->addMicroseconds(900),
        'created_at' => $second,
        'updated_at' => $second,
    ]);

    $postHog = Mockery::mock(PostHogService::class);
    $postHog->shouldReceive('groupIdentifyNow')
        ->once()
        ->with('account', (string) $this->account->id, [
            'last_post_published_at' => $latestPublication->published_at->toIso8601String(),
            'last_post_published_network' => 'linkedin',
            'last_published_post_id' => $post->id,
        ]);

    (new SyncAccountPublishingActivity((string) $this->account->id))->handle($postHog);
});

test('handle is a no-op when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);

    $postHog = Mockery::mock(PostHogService::class);
    $postHog->shouldNotReceive('groupIdentifyNow');

    (new SyncAccountPublishingActivity((string) $this->account->id))->handle($postHog);
});

test('handle returns silently when the account no longer exists', function () {
    $postHog = Mockery::mock(PostHogService::class);
    $postHog->shouldNotReceive('groupIdentifyNow');

    (new SyncAccountPublishingActivity((string) Str::uuid()))->handle($postHog);
});

test('job is unique while delayed and serializes sends per account', function () {
    $job = new SyncAccountPublishingActivity((string) $this->account->id);
    $middleware = $job->middleware();

    expect($job)->toBeInstanceOf(ShouldBeUniqueUntilProcessing::class)
        ->and($job->uniqueId())->toBe((string) $this->account->id)
        ->and($job->uniqueFor)->toBe(3600)
        ->and($job->backoff())->toBe([60, 300, 900, 1800, 3600])
        ->and($job->queue)->toBe('posthog')
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe("posthog-account-publishing:{$this->account->id}")
        ->and($middleware[0]->releaseAfter)->toBe(30)
        ->and($middleware[0]->expiresAfter)->toBe($job->timeout + 30);
});

test('unique lock drops duplicate pending syncs for the same account', function () {
    Bus::fake([SyncAccountPublishingActivity::class]);

    SyncAccountPublishingActivity::dispatch((string) $this->account->id)->delay(now()->addMinutes(5));
    SyncAccountPublishingActivity::dispatch((string) $this->account->id)->delay(now()->addMinutes(5));

    Bus::assertDispatchedTimes(SyncAccountPublishingActivity::class, 1);
});

test('unique lock allows pending syncs for different accounts', function () {
    $otherAccount = Account::factory()->create();
    Bus::fake([SyncAccountPublishingActivity::class]);

    SyncAccountPublishingActivity::dispatch((string) $this->account->id)->delay(now()->addMinutes(5));
    SyncAccountPublishingActivity::dispatch((string) $otherAccount->id)->delay(now()->addMinutes(5));

    Bus::assertDispatchedTimes(SyncAccountPublishingActivity::class, 2);
});

test('overlap lock serializes processing for the same account', function () {
    $runningJob = new SyncAccountPublishingActivity((string) $this->account->id);
    $overlappingJob = (new SyncAccountPublishingActivity((string) $this->account->id))
        ->withFakeQueueInteractions();
    /** @var WithoutOverlapping $middleware */
    $middleware = $overlappingJob->middleware()[0];
    $lock = Cache::lock($middleware->getLockKey($runningJob), $runningJob->timeout + 30);
    $handled = false;

    expect($lock->get())->toBeTrue();

    try {
        $middleware->handle($overlappingJob, function () use (&$handled): void {
            $handled = true;
        });
    } finally {
        $lock->release();
    }

    expect($handled)->toBeFalse();
    $overlappingJob->assertReleased(30);

    $middleware->handle($overlappingJob, function () use (&$handled): void {
        $handled = true;
    });

    expect($handled)->toBeTrue();
});
