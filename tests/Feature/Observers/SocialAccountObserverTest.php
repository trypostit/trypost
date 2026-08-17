<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Models\SocialAccount;
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

test('creating a social account identifies the owner with connected platforms', function () {
    Bus::fake();

    SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    Bus::assertDispatched(SendEvent::class, function (SendEvent $event): bool {
        return $event->method === 'identify'
            && data_get($event->payload, 'distinctId') === $this->user->id
            && data_get($event->payload, 'properties.connected_platforms') === [Platform::LinkedIn->value];
    });
});

test('deleting a social account identifies the owner without that platform', function () {
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    Bus::fake();

    $socialAccount->delete();

    Bus::assertDispatched(SendEvent::class, function (SendEvent $event): bool {
        return $event->method === 'identify'
            && data_get($event->payload, 'distinctId') === $this->user->id
            && data_get($event->payload, 'properties.connected_platforms') === [];
    });
});

test('disconnecting a social account identifies the owner without that platform', function () {
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    Bus::fake();

    $socialAccount->update(['status' => Status::Disconnected]);

    Bus::assertDispatched(SendEvent::class, function (SendEvent $event): bool {
        return $event->method === 'identify'
            && data_get($event->payload, 'distinctId') === $this->user->id
            && data_get($event->payload, 'properties.connected_platforms') === [];
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

    $socialAccount->update(['is_active' => false]);

    Bus::assertNotDispatched(SyncAccountUsage::class);
    Bus::assertNotDispatched(SendEvent::class);
});

test('does not dispatch when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);

    Bus::fake();

    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    Bus::assertNotDispatched(SyncAccountUsage::class);
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
