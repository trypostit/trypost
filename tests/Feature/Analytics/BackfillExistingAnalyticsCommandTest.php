<?php

declare(strict_types=1);

use App\Actions\Analytics\ResolveAnalyticsAccountKey;
use App\Enums\Analytics\SyncCollector;
use App\Enums\Analytics\SyncStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Status;
use App\Jobs\Analytics\BackfillTryPostPublications;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Models\Account;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsSyncState;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

test('local publication backfill persists only successful included destinations with live identities', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $published = PostPlatform::factory()->instagram()->published()->create([
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);
    $published->post->update(['workspace_id' => $workspace->id]);
    $failed = PostPlatform::factory()->instagram()->failed()->create([
        'social_account_id' => $account->id,
        'platform' => $account->platform,
    ]);

    app()->call([new BackfillTryPostPublications([$published->id, $failed->id]), 'handle']);

    expect(AnalyticsPublication::query()->where('post_platform_id', $published->id)->exists())->toBeTrue()
        ->and(AnalyticsPublication::query()->where('post_platform_id', $failed->id)->exists())->toBeFalse();
});

test('rollout command dispatches bounded jobs only for eligible scoped accounts and destinations', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $eligible = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'is_active' => true,
        'status' => Status::Connected,
    ]);
    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $workspace->id,
        'is_active' => true,
        'status' => Status::Connected,
    ]);
    SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'is_active' => false,
        'status' => Status::Connected,
    ]);
    $published = PostPlatform::factory()->instagram()->published()->create([
        'social_account_id' => $eligible->id,
        'platform' => $eligible->platform,
    ]);
    $published->post->update(['workspace_id' => $workspace->id]);
    PostPlatform::factory()->instagram()->create([
        'social_account_id' => $eligible->id,
        'platform' => $eligible->platform,
        'status' => PostPlatformStatus::Failed,
    ]);
    Bus::fake();

    expect(SocialAccount::query()->connected()->active()->includedInAnalytics()
        ->where('workspace_id', $workspace->id)->pluck('id')->all())->toBe([$eligible->id]);

    $this->artisan('analytics:backfill-existing', ['--workspace' => $workspace->id, '--include-unsubscribed' => true])
        ->assertSuccessful();

    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $eligible->id);
    Bus::assertDispatched(CollectAccountDailySnapshot::class, fn ($job): bool => $job->socialAccountId === $eligible->id && $job->observationDate === now('UTC')->toDateString());
    Bus::assertDispatched(BackfillTryPostPublications::class, fn ($job): bool => $job->postPlatformIds === [$published->id]);
    Bus::assertDispatchedTimes(BootstrapAccountAnalytics::class, 1);
    Bus::assertDispatchedTimes(BackfillTryPostPublications::class, 1);
});

test('rollout reports orphaned historical destinations without inventing an identity', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $destination = PostPlatform::factory()->instagram()->published()->create();
    $destination->post->update(['workspace_id' => $workspace->id]);
    $destination->updateQuietly(['social_account_id' => null]);
    Bus::fake();

    $this->artisan('analytics:backfill-existing', ['--workspace' => $workspace->id, '--include-unsubscribed' => true])
        ->expectsOutputToContain('historical_identity_unrecoverable=1')
        ->assertSuccessful();

    Bus::assertNotDispatched(BackfillTryPostPublications::class);
});

test('deployment rollout dispatches only for workspaces with active paid subscriptions', function () {
    Bus::fake();
    $eligibleAccounts = [];
    $eligibleDestinations = [];
    $workspaces = [];

    foreach (['active', 'trialing', 'past_due', 'grace_period', 'ended', 'none'] as $status) {
        $workspace = Workspace::factory()->create();
        $workspaces[$status] = $workspace;

        if ($status !== 'none') {
            $workspace->account->subscriptions()->create([
                'type' => Account::SUBSCRIPTION_NAME,
                'stripe_id' => 'sub_'.fake()->uuid(),
                'stripe_status' => in_array($status, ['grace_period', 'ended'], true) ? 'active' : $status,
                'stripe_price' => 'price_123',
                'ends_at' => match ($status) {
                    'grace_period' => now()->addDay(),
                    'ended' => now()->subDay(),
                    default => null,
                },
            ]);
        }

        $socialAccount = SocialAccount::factory()->instagram()->create([
            'workspace_id' => $workspace->id,
            'is_active' => true,
            'status' => Status::Connected,
        ]);
        $destination = PostPlatform::factory()->instagram()->published()->create([
            'post_id' => Post::factory()->create(['workspace_id' => $workspace->id])->id,
            'social_account_id' => $socialAccount->id,
        ]);

        if (in_array($status, ['active', 'grace_period', 'trialing'], true)) {
            $orphan = PostPlatform::factory()->instagram()->published()->create([
                'post_id' => Post::factory()->create(['workspace_id' => $workspace->id])->id,
                'social_account_id' => $socialAccount->id,
            ]);
            $orphan->updateQuietly(['social_account_id' => null]);
        }

        if (in_array($status, ['active', 'grace_period'], true)) {
            $eligibleAccounts[] = $socialAccount->id;
            $eligibleDestinations[] = $destination->id;
        }
    }

    Bus::fake();
    $this->artisan('analytics:backfill-existing')
        ->expectsOutputToContain('historical_identity_unrecoverable=2')
        ->assertSuccessful();

    expect(Bus::dispatched(BootstrapAccountAnalytics::class)->pluck('socialAccountId')->sort()->values()->all())->toBe(collect($eligibleAccounts)->sort()->values()->all())
        ->and(Bus::dispatched(CollectAccountDailySnapshot::class)->pluck('socialAccountId')->sort()->values()->all())->toBe(collect($eligibleAccounts)->sort()->values()->all())
        ->and(Bus::dispatched(BackfillTryPostPublications::class)->flatMap->postPlatformIds->sort()->values()->all())->toBe(collect($eligibleDestinations)->sort()->values()->all());

    Bus::fake();
    $this->artisan('analytics:backfill-existing', [
        '--workspace' => $workspaces['trialing']->id,
    ])->assertSuccessful();

    Bus::assertNotDispatched(BootstrapAccountAnalytics::class);
    Bus::assertNotDispatched(BackfillTryPostPublications::class);
});

test('account backfill visits every identity once across ID pages despite platform sorting', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    SocialAccount::factory()->instagram()->count(100)->create([
        'workspace_id' => $workspace->id,
        'is_active' => true,
        'status' => Status::Connected,
    ]);
    SocialAccount::factory()->facebook()->create([
        'id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
        'workspace_id' => $workspace->id,
        'is_active' => true,
        'status' => Status::Connected,
    ]);
    Bus::fake();

    $this->artisan('analytics:backfill-existing', ['--workspace' => $workspace->id, '--include-unsubscribed' => true])->assertSuccessful();

    $jobs = Bus::dispatched(BootstrapAccountAnalytics::class);
    expect($jobs)->toHaveCount(101)
        ->and($jobs->pluck('socialAccountId')->unique())->toHaveCount(101);
});

test('manual rollout rerun only redispatches missing publications and unfinished account bootstraps', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'is_active' => true,
    ]);
    $destination = PostPlatform::factory()->instagram()->published()->create([
        'post_id' => Post::factory()->create(['workspace_id' => $workspace->id])->id,
        'social_account_id' => $account->id,
    ]);
    Bus::fake();

    $this->artisan('analytics:backfill-existing', [
        '--workspace' => $workspace->id,
        '--include-unsubscribed' => true,
    ])->assertSuccessful();

    Bus::assertDispatched(CollectAccountDailySnapshot::class, fn ($job): bool => $job->socialAccountId === $account->id);
    Bus::fake();
    $this->artisan('analytics:backfill-existing', [
        '--workspace' => $workspace->id,
        '--include-unsubscribed' => true,
    ])->assertSuccessful();

    Bus::assertDispatched(BackfillTryPostPublications::class, fn ($job): bool => $job->postPlatformIds === [$destination->id]);
    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $account->id);
    app()->call([new BackfillTryPostPublications([$destination->id]), 'handle']);
    (new BootstrapAccountAnalytics($account->id))->handleFor($account->id);
    Bus::fake();

    $this->artisan('analytics:backfill-existing', [
        '--workspace' => $workspace->id,
        '--include-unsubscribed' => true,
    ])->assertSuccessful();

    Bus::assertNotDispatched(BackfillTryPostPublications::class);
    Bus::assertNotDispatched(BootstrapAccountAnalytics::class);
    Bus::assertNotDispatched(CollectAccountDailySnapshot::class);
});

test('manual rollout rerun recovers a stale bootstrap without repeating a recent one', function () {
    Bus::fake();
    $workspace = Workspace::factory()->create();
    $staleAccount = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $recentAccount = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $queueFailedAccount = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);
    $permissionFailedAccount = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id, 'is_active' => true]);

    foreach ([$staleAccount, $recentAccount, $queueFailedAccount, $permissionFailedAccount] as $account) {
        AnalyticsSyncState::factory()->create([
            ...AnalyticsSyncState::identityFor($account),
            'social_account_id' => $account->id,
            'collector' => SyncCollector::PublicationBackfill,
            'status' => in_array($account->id, [$queueFailedAccount->id, $permissionFailedAccount->id], true)
                ? SyncStatus::Failed
                : SyncStatus::Pending,
            'last_error_category' => match ($account->id) {
                $queueFailedAccount->id => 'queue_failed',
                $permissionFailedAccount->id => 'permission',
                default => null,
            },
            'updated_at' => $account->is($recentAccount) ? now() : now()->subHours(3),
        ]);
    }

    Bus::fake();
    $this->artisan('analytics:backfill-existing', [
        '--workspace' => $workspace->id,
        '--include-unsubscribed' => true,
    ])->assertSuccessful();

    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $staleAccount->id);
    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $queueFailedAccount->id);
    Bus::assertDispatchedTimes(BootstrapAccountAnalytics::class, 2);
    Bus::assertNotDispatched(CollectAccountDailySnapshot::class);
});

test('rollout bootstrap and local publication jobs retry transient failures', function () {
    $bootstrap = new BootstrapAccountAnalytics(fake()->uuid());
    $publications = new BackfillTryPostPublications([fake()->uuid()]);

    expect($bootstrap->tries)->toBe(3)
        ->and($bootstrap->backoff())->toBe([60, 300])
        ->and($publications->tries)->toBe(3)
        ->and($publications->backoff())->toBe([60, 300]);
});

test('local backfill resolves one account identity per batch without repeated account reads', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $destinations = collect(range(1, 3))->map(function () use ($account, $workspace): PostPlatform {
        $destination = PostPlatform::factory()->instagram()->published()->create([
            'social_account_id' => $account->id,
            'platform' => $account->platform,
        ]);
        $destination->post->update(['workspace_id' => $workspace->id]);

        return $destination;
    });
    $accountKeys = Mockery::mock(ResolveAnalyticsAccountKey::class);
    $accountKeys->shouldReceive('for')->once()->andReturn($account->id);
    app()->instance(ResolveAnalyticsAccountKey::class, $accountKeys);
    $accountReads = [];
    DB::listen(function ($query) use (&$accountReads): void {
        if (str_contains($query->sql, 'social_accounts')) {
            $accountReads[] = $query->sql;
        }
    });

    app()->call([new BackfillTryPostPublications($destinations->pluck('id')->all()), 'handle']);

    expect($accountReads)->toHaveCount(1)
        ->and(AnalyticsPublication::query()->whereIn('post_platform_id', $destinations->pluck('id'))->count())->toBe(3);
});
