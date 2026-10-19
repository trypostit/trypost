<?php

declare(strict_types=1);

use App\Actions\Workspace\PurgeWorkspace;
use App\Enums\PostHog\SocialAccountEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackSocialAccountDisconnected;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('job is queued on the posthog queue with retries and a timeout', function () {
    $job = new TrackSocialAccountDisconnected(null, (string) Str::uuid(), (string) Str::uuid(), []);

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('handle captures social_account.disconnected from the snapshot even when the channel is gone', function () {
    Queue::fake();

    $socialAccountId = (string) Str::uuid();

    (new TrackSocialAccountDisconnected($socialAccountId, (string) $this->account->id, (string) $this->user->id, [
        'platform' => 'instagram-facebook',
        'network' => 'instagram',
        'workspace_id' => (string) $this->workspace->id,
        'reason' => 'removed',
    ]))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === SocialAccountEvent::Disconnected->value
        && $job->payload['distinctId'] === (string) $this->user->id
        && $job->payload['properties']['social_account_id'] === $socialAccountId
        && $job->payload['properties']['platform'] === 'instagram-facebook'
        && $job->payload['properties']['network'] === 'instagram'
        && $job->payload['properties']['workspace_id'] === (string) $this->workspace->id
        && $job->payload['properties']['reason'] === 'removed'
        && $job->payload['properties']['$groups']['account'] === (string) $this->account->id);
});

test('handle returns silently when the account is gone', function () {
    Queue::fake();

    (new TrackSocialAccountDisconnected(null, (string) Str::uuid(), (string) $this->user->id, ['reason' => 'removed']))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('user disconnect dispatches disconnected with reason removed', function () {
    $member = User::factory()->create(['account_id' => $this->account->id, 'current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('admin'));
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    Bus::fake([TrackSocialAccountDisconnected::class]);

    $this->actingAs($member)->delete(route('app.channels.disconnect', $socialAccount))->assertRedirect();

    expect(SocialAccount::find($socialAccount->id))->toBeNull();

    Bus::assertDispatchedTimes(TrackSocialAccountDisconnected::class, 1);
    Bus::assertDispatched(TrackSocialAccountDisconnected::class, fn (TrackSocialAccountDisconnected $job): bool => $job->socialAccountId === (string) $socialAccount->id
        && $job->accountId === (string) $this->account->id
        && $job->userId === (string) $member->id
        && $job->snapshot === [
            'platform' => 'linkedin',
            'network' => 'linkedin',
            'workspace_id' => (string) $this->workspace->id,
            'reason' => 'removed',
        ]);
});

test('user disconnect does not dispatch when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    Bus::fake([TrackSocialAccountDisconnected::class]);

    $this->actingAs($this->user)->delete(route('app.channels.disconnect', $socialAccount))->assertRedirect();

    Bus::assertNotDispatched(TrackSocialAccountDisconnected::class);
});

test('deleting a workspace does not dispatch disconnected', function () {
    SocialAccount::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    Bus::fake([TrackSocialAccountDisconnected::class]);

    PurgeWorkspace::execute($this->workspace);

    expect(SocialAccount::query()->where('workspace_id', $this->workspace->id)->exists())->toBeFalse();

    Bus::assertNotDispatched(TrackSocialAccountDisconnected::class);
});
