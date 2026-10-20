<?php

declare(strict_types=1);

use App\Actions\Account\ResolveLifecycleStage;
use App\Actions\User\CreateUser;
use App\Console\Commands\SyncPostHogLifecycleStages;
use App\Enums\Account\LifecycleStage;
use App\Enums\PostHog\ActivationEvent;
use App\Enums\PostHog\LifecycleEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackLifecycleChange;
use App\Models\Account;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.posthog.enabled' => true,
        'services.posthog.api_key' => 'phc_test_key',
        'trypost.self_hosted' => false,
    ]);

    $this->account = Account::factory()->create(['trial_ends_at' => null]);
    $this->owner = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->owner->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);
    subscribeAccount($this->account);
});

/**
 * @return list<SendEvent>
 */
function pushedLifecycleCaptures(): array
{
    $lifecycleEvents = array_column([...LifecycleEvent::cases(), ...ActivationEvent::cases()], 'value');

    return Queue::pushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && in_array($job->payload['event'], $lifecycleEvents, true))->all();
}

test('first run sets properties without capturing events', function () {
    $this->freezeTime();
    Queue::fake([SendEvent::class]);
    $this->account->forceFill(['lifecycle_stage' => null, 'lifecycle_stage_changed_at' => null])->saveQuietly();
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->forAccount($channel)->published()->create(['published_at' => now()->subDays(3)]);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    $this->account->refresh();

    expect($this->account->lifecycle_stage)->toBe(LifecycleStage::Active)
        ->and($this->account->lifecycle_stage_changed_at?->toIso8601String())->toBe(now()->toIso8601String())
        ->and($this->account->activated_at?->toIso8601String())->toBe(now()->subDays(3)->toIso8601String())
        ->and(pushedLifecycleCaptures())->toBeEmpty();

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'groupIdentify'
        && $job->payload['groupKey'] === (string) $this->account->id
        && data_get($job->payload, 'properties.lifecycle_stage') === LifecycleStage::Active->value
        && data_get($job->payload, 'properties.activated_at') === now()->subDays(3)->toIso8601String());
    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'identify'
        && $job->payload['distinctId'] === (string) $this->owner->id
        && data_get($job->payload, 'properties.lifecycle_stage') === LifecycleStage::Active->value);
});

test('stage change captures previous and new stage', function () {
    Queue::fake([SendEvent::class]);
    $this->account->forceFill(['lifecycle_stage' => LifecycleStage::Onboarding])->saveQuietly();
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    expect($this->account->refresh()->lifecycle_stage)->toBe(LifecycleStage::Connected);

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === LifecycleEvent::StageChanged->value
        && $job->payload['distinctId'] === (string) $this->owner->id
        && $job->payload['properties']['stage'] === LifecycleStage::Connected->value
        && $job->payload['properties']['previous_stage'] === LifecycleStage::Onboarding->value
        && $job->payload['properties']['$groups']['account'] === (string) $this->account->id);
    Queue::assertNotPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === ActivationEvent::Reached->value);
});

test('first publish captures activation.reached once', function () {
    Queue::fake([SendEvent::class]);
    $this->account->forceFill(['lifecycle_stage' => LifecycleStage::Connected])->saveQuietly();
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->forAccount($channel)->published()->create(['published_at' => now()->subHour()]);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();
    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    $activations = collect(pushedLifecycleCaptures())->whereStrict('payload.event', ActivationEvent::Reached->value);

    expect($activations)->toHaveCount(1)
        ->and($activations->first()->payload['properties']['channels_count'])->toBe(1)
        ->and($activations->first()->payload['properties']['reached_at'])->toBe($this->account->refresh()->activated_at?->toIso8601String())
        ->and($this->account->lifecycle_stage)->toBe(LifecycleStage::Active);
});

test('activation keeps the first published post when the account already has an activation date', function () {
    Bus::fake([TrackLifecycleChange::class]);
    $activatedAt = now()->subDays(40)->startOfSecond();
    $this->account->forceFill(['lifecycle_stage' => LifecycleStage::Active, 'activated_at' => $activatedAt])->saveQuietly();
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->forAccount($channel)->published()->create(['published_at' => now()->subDay()]);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    expect($this->account->refresh()->activated_at?->toIso8601String())->toBe($activatedAt->toIso8601String());
    Bus::assertNotDispatched(TrackLifecycleChange::class, fn (TrackLifecycleChange $job): bool => $job->accountId === (string) $this->account->id);
});

test('a new account that publishes before its first sync captures its activation and stage change', function () {
    Queue::fake([SendEvent::class]);
    $owner = CreateUser::execute([
        'name' => 'Jane Doe',
        'email' => 'jane-lifecycle@example.com',
        'password' => 'secret123',
    ]);
    subscribeAccount($owner->account);
    $channel = SocialAccount::factory()->create(['workspace_id' => $owner->current_workspace_id]);
    Post::factory()->forAccount($channel)->published()->create(['published_at' => now()->subMinutes(10)]);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    $captures = collect(pushedLifecycleCaptures())->whereStrict('payload.properties.account_id', (string) $owner->account_id);

    expect($captures->firstWhere('payload.event', ActivationEvent::Reached->value))->not->toBeNull()
        ->and($captures->firstWhere('payload.event', LifecycleEvent::StageChanged->value)?->payload['properties'])
        ->toMatchArray([
            'stage' => LifecycleStage::Active->value,
            'previous_stage' => LifecycleStage::SignedUp->value,
        ]);
});

test('the lifecycle write does not move the account updated_at', function () {
    Bus::fake([TrackLifecycleChange::class]);
    $updatedAt = now()->subWeek()->startOfSecond();
    $this->account->forceFill(['updated_at' => $updatedAt])->saveQuietly();

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    $this->account->refresh();

    expect($this->account->lifecycle_stage)->toBe(LifecycleStage::Onboarding)
        ->and($this->account->updated_at?->toIso8601String())->toBe($updatedAt->toIso8601String());
});

test('unchanged stage dispatches nothing', function () {
    Bus::fake([TrackLifecycleChange::class]);
    $changedAt = now()->subWeek()->startOfSecond();
    $this->account->forceFill([
        'lifecycle_stage' => LifecycleStage::Onboarding,
        'lifecycle_stage_changed_at' => $changedAt,
    ])->saveQuietly();

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    expect($this->account->refresh()->lifecycle_stage_changed_at?->toIso8601String())->toBe($changedAt->toIso8601String());
    Bus::assertNotDispatched(TrackLifecycleChange::class, fn (TrackLifecycleChange $job): bool => $job->accountId === (string) $this->account->id);
});

test('imported network posts do not activate', function () {
    Bus::fake([TrackLifecycleChange::class]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->forAccount($channel)->imported()->create();

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    $this->account->refresh();

    expect($this->account->lifecycle_stage)->toBe(LifecycleStage::Connected)
        ->and($this->account->activated_at)->toBeNull();
    Bus::assertDispatched(TrackLifecycleChange::class, fn (TrackLifecycleChange $job): bool => $job->accountId === (string) $this->account->id
        && $job->previousStage === null
        && $job->stage === LifecycleStage::Connected
        && $job->activatedNow === false);
});

test('a disconnected channel leaves onboarding and other accounts posts do not count', function () {
    Bus::fake([TrackLifecycleChange::class]);
    SocialAccount::factory()->disconnected()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->published()->create();

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    expect($this->account->refresh()->lifecycle_stage)->toBe(LifecycleStage::Connected);
});

test('an account that lost access after paying is churned', function () {
    Bus::fake([TrackLifecycleChange::class]);
    $this->account->forceFill(['first_paid_at' => now()->subMonth()])->save();
    $this->account->subscriptions()->update(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    expect($this->account->refresh()->lifecycle_stage)->toBe(LifecycleStage::Churned);
});

test('command writes nothing when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Bus::fake([TrackLifecycleChange::class]);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    expect($this->account->refresh()->lifecycle_stage)->toBeNull();
    Bus::assertNotDispatched(TrackLifecycleChange::class);
});

test('command is scheduled hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'posthog:sync-lifecycle-stages'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue()
        ->and((new SyncPostHogLifecycleStages)->getName())->toBe('posthog:sync-lifecycle-stages');
});

test('the schedule only runs the command when PostHog is enabled', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'posthog:sync-lifecycle-stages'));

    expect($event->filtersPass(app()))->toBeTrue();

    config(['services.posthog.enabled' => false]);

    expect($event->filtersPass(app()))->toBeFalse();
});

test('an account whose only channel expired is not onboarding', function () {
    Queue::fake([SendEvent::class]);
    $this->account->forceFill(['lifecycle_stage' => LifecycleStage::Active])->saveQuietly();
    $channel = SocialAccount::factory()->tokenExpired()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->forAccount($channel)->published()->create(['published_at' => now()->subDay()]);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    expect($this->account->refresh()->lifecycle_stage)->toBe(LifecycleStage::Active);
});

test('a stage going backwards captures the previous stage', function () {
    Queue::fake([SendEvent::class]);
    $this->account->forceFill(['lifecycle_stage' => LifecycleStage::Active, 'activated_at' => now()->subMonths(2)])->saveQuietly();
    $channel = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->forAccount($channel)->published()->create(['published_at' => now()->subDays(ResolveLifecycleStage::AT_RISK_WITHIN_DAYS + 5)]);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    expect($this->account->refresh()->lifecycle_stage)->toBe(LifecycleStage::Dormant);
    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === LifecycleEvent::StageChanged->value
        && data_get($job->payload, 'properties.previous_stage') === LifecycleStage::Active->value
        && data_get($job->payload, 'properties.stage') === LifecycleStage::Dormant->value);
});

test('an account whose change cannot be queued keeps its stage and the run goes on', function () {
    Queue::fake([SendEvent::class]);
    $this->account->forceFill(['lifecycle_stage' => LifecycleStage::Onboarding])->saveQuietly();
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $otherAccount = Account::factory()->create(['trial_ends_at' => null, 'lifecycle_stage' => LifecycleStage::Onboarding]);
    $otherOwner = User::factory()->create(['account_id' => $otherAccount->id]);
    $otherAccount->update(['owner_id' => $otherOwner->id]);
    $otherWorkspace = Workspace::factory()->create(['account_id' => $otherAccount->id, 'user_id' => $otherOwner->id]);
    subscribeAccount($otherAccount);
    SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    failDispatchOf(TrackLifecycleChange::class, fn (TrackLifecycleChange $job): bool => $job->accountId === (string) $this->account->id);

    $this->artisan('posthog:sync-lifecycle-stages')->assertSuccessful();

    expect($this->account->refresh()->lifecycle_stage)->toBe(LifecycleStage::Onboarding)
        ->and($this->account->lifecycle_stage_changed_at)->toBeNull()
        ->and($otherAccount->refresh()->lifecycle_stage)->toBe(LifecycleStage::Connected);
});
