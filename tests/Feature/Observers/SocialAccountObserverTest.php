<?php

declare(strict_types=1);

use App\Enums\PostHog\SocialAccountEvent;
use App\Enums\SocialAccount\Status;
use App\Jobs\Analytics\BootstrapAccountAnalytics;
use App\Jobs\Analytics\CollectAccountDailySnapshot;
use App\Jobs\PostHog\IdentifyConnectedPlatforms;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Jobs\PostHog\TrackSocialAccountConnectionLost;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
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
});

test('creating a social account dispatches IdentifyConnectedPlatforms', function () {
    Bus::fake();

    SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    Bus::assertDispatched(IdentifyConnectedPlatforms::class, function (IdentifyConnectedPlatforms $job): bool {
        return $job->workspaceId === (string) $this->workspace->id;
    });
});

test('deleting a social account dispatches IdentifyConnectedPlatforms', function () {
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    Bus::fake();

    $socialAccount->delete();

    Bus::assertDispatched(IdentifyConnectedPlatforms::class, function (IdentifyConnectedPlatforms $job): bool {
        return $job->workspaceId === (string) $this->workspace->id;
    });
});

test('disconnecting a social account dispatches IdentifyConnectedPlatforms', function () {
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    Bus::fake();

    $socialAccount->update(['status' => Status::Disconnected]);

    Bus::assertDispatched(IdentifyConnectedPlatforms::class, function (IdentifyConnectedPlatforms $job): bool {
        return $job->workspaceId === (string) $this->workspace->id;
    });
});

test('creating a social account dispatches SyncAccountUsage', function () {
    Bus::fake();

    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    Bus::assertDispatched(SyncAccountUsage::class, function ($job) {
        return $job->accountId === (string) $this->account->id
            && $job->workspaceId === (string) $this->workspace->id;
    });
});

test('deleting a social account dispatches SyncAccountUsage', function () {
    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    Bus::fake();

    $socialAccount->delete();

    Bus::assertDispatched(SyncAccountUsage::class, function ($job) {
        return $job->accountId === (string) $this->account->id
            && $job->workspaceId === (string) $this->workspace->id;
    });
});

test('updating a social account does not dispatch SyncAccountUsage', function () {
    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    Bus::fake();

    $socialAccount->update(['display_name' => 'Renamed']);

    Bus::assertNotDispatched(SyncAccountUsage::class);
    Bus::assertNotDispatched(IdentifyConnectedPlatforms::class);
    Bus::assertNotDispatched(SendEvent::class);
});

test('does not dispatch when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);

    Bus::fake();

    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    Bus::assertNotDispatched(SyncAccountUsage::class);
    Bus::assertNotDispatched(IdentifyConnectedPlatforms::class);
    Bus::assertNotDispatched(SendEvent::class);
});

test('does not identify connected platforms when self-hosted without PostHog', function () {
    config([
        'trypost.self_hosted' => true,
        'services.posthog.enabled' => false,
        'services.posthog.api_key' => null,
    ]);

    Bus::fake();

    $socialAccount = SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->assertModelExists($socialAccount);
    Bus::assertNotDispatched(SyncAccountUsage::class);
    Bus::assertNotDispatched(IdentifyConnectedPlatforms::class);
    Bus::assertNotDispatched(SendEvent::class);
});

test('updating status on multiple batch-hydrated social accounts does not throw a lazy loading violation', function () {

    $accounts = SocialAccount::factory()->count(2)->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
    ]);

    $batch = SocialAccount::query()
        ->whereIn('id', $accounts->pluck('id'))
        ->get();

    foreach ($batch as $socialAccount) {
        $socialAccount->update(['status' => Status::Disconnected]);
    }
})->throwsNoExceptions();

test('connecting an included account dispatches its initial follower collection', function () {
    Bus::fake();

    $socialAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    Bus::assertDispatched(CollectAccountDailySnapshot::class, fn ($job): bool => $job->socialAccountId === $socialAccount->id
        && $job->queue === 'analytics');
    Bus::assertDispatched(BootstrapAccountAnalytics::class, fn ($job): bool => $job->socialAccountId === $socialAccount->id
        && $job->queue === 'analytics');
});

test('connecting an excluded account does not dispatch follower collection', function () {
    Bus::fake();

    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    Bus::assertNotDispatched(CollectAccountDailySnapshot::class);
    Bus::assertNotDispatched(BootstrapAccountAnalytics::class);
});

test('token expiry dispatches the lost connection with its status', function () {
    $socialAccount = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
    ]);

    Bus::fake([TrackSocialAccountConnectionLost::class]);

    $socialAccount->markAsTokenExpired('Token revoked', notify: false);

    Bus::assertDispatchedOnce(TrackSocialAccountConnectionLost::class);
    Bus::assertDispatched(TrackSocialAccountConnectionLost::class, fn (TrackSocialAccountConnectionLost $job): bool => $job->socialAccountId === (string) $socialAccount->id
        && $job->status === Status::TokenExpired);
});

test('a status change to disconnected dispatches the lost connection with that status', function () {
    $socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
    ]);

    Bus::fake([TrackSocialAccountConnectionLost::class]);

    $socialAccount->update(['status' => Status::Disconnected]);

    Bus::assertDispatched(TrackSocialAccountConnectionLost::class, fn (TrackSocialAccountConnectionLost $job): bool => $job->status === Status::Disconnected);
});

test('a channel that was not connected does not dispatch disconnected again', function () {
    $socialAccount = SocialAccount::factory()->tokenExpired()->create(['workspace_id' => $this->workspace->id]);

    Bus::fake([TrackSocialAccountConnectionLost::class]);

    $socialAccount->update(['status' => Status::Disconnected]);
    $socialAccount->update(['status' => Status::Connected]);

    Bus::assertNotDispatched(TrackSocialAccountConnectionLost::class);
});

test('a status change does not dispatch disconnected when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);

    $socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
    ]);

    Bus::fake([TrackSocialAccountConnectionLost::class]);

    $socialAccount->update(['status' => Status::TokenExpired]);

    Bus::assertNotDispatched(TrackSocialAccountConnectionLost::class);
});

test('a token expiry rolled back with its transaction sends no disconnected event', function () {
    $socialAccount = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
    ]);

    Queue::fake([SendEvent::class]);

    rescue(fn () => DB::transaction(function () use ($socialAccount): void {
        $socialAccount->markAsTokenExpired('Token revoked', notify: false);

        throw new RuntimeException('Rolled back.');
    }), report: false);

    expect($socialAccount->fresh()->status)->toBe(Status::Connected);
    Queue::assertNotPushed(SendEvent::class, fn (SendEvent $job): bool => data_get($job->payload, 'event') === SocialAccountEvent::Disconnected->value);

    $socialAccount->fresh()->markAsTokenExpired('Token revoked', notify: false);

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => data_get($job->payload, 'event') === SocialAccountEvent::Disconnected->value);
});

test('a status save survives a failing disconnected dispatch', function () {
    $socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
    ]);

    failDispatchOf(TrackSocialAccountConnectionLost::class);

    $socialAccount->update(['status' => Status::TokenExpired]);

    expect(SocialAccount::query()->find($socialAccount->id)->status)->toBe(Status::TokenExpired);
});

test('a status saved inside a transaction survives a disconnected push that fails at commit', function () {
    $socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Connected,
    ]);

    Queue::fake([SyncAccountUsage::class, IdentifyConnectedPlatforms::class]);
    Queue::before(function (JobProcessing $event): void {
        if ($event->job->resolveName() === TrackSocialAccountConnectionLost::class) {
            throw new RuntimeException('Queue is down.');
        }
    });

    DB::transaction(fn () => $socialAccount->update(['status' => Status::TokenExpired]));

    expect(SocialAccount::query()->find($socialAccount->id)->status)->toBe(Status::TokenExpired);
});
