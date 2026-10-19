<?php

declare(strict_types=1);

use App\Enums\Account\LifecycleStage;
use App\Enums\PostHog\LifecycleEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackLifecycleChange;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->freezeTime();
    $this->account = Account::factory()->create();
    $this->account->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();
    $this->owner = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->owner->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);
});

test('job is queued on the posthog queue with retries and a timeout', function () {
    $job = new TrackLifecycleChange((string) Str::uuid(), null, LifecycleStage::SignedUp, false);

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('a first stage only identifies the account and its owner', function () {
    Queue::fake();
    $this->account->forceFill([
        'lifecycle_stage' => LifecycleStage::Active,
        'lifecycle_stage_changed_at' => now(),
        'activated_at' => now()->subDay(),
    ])->saveQuietly();

    (new TrackLifecycleChange((string) $this->account->id, null, LifecycleStage::Active, true))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'groupIdentify'
        && $job->payload['groupType'] === 'account'
        && $job->payload['groupKey'] === (string) $this->account->id
        && $job->payload['properties'] === [
            'lifecycle_stage' => LifecycleStage::Active->value,
            'lifecycle_stage_changed_at' => now()->toIso8601String(),
            'activated_at' => now()->subDay()->toIso8601String(),
        ]);
    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'identify'
        && $job->payload['distinctId'] === (string) $this->owner->id
        && $job->payload['properties'] === ['lifecycle_stage' => LifecycleStage::Active->value]);
    Queue::assertNotPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture');
});

test('a stage change captures lifecycle_stage.changed for the owner', function () {
    Queue::fake();
    $this->account->forceFill(['lifecycle_stage' => LifecycleStage::AtRisk, 'lifecycle_stage_changed_at' => now()])->saveQuietly();

    (new TrackLifecycleChange((string) $this->account->id, LifecycleStage::Active, LifecycleStage::AtRisk, false))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === LifecycleEvent::StageChanged->value
        && $job->payload['distinctId'] === (string) $this->owner->id
        && $job->payload['properties']['stage'] === LifecycleStage::AtRisk->value
        && $job->payload['properties']['previous_stage'] === LifecycleStage::Active->value
        && $job->payload['properties']['reached_at'] === now()->toIso8601String()
        && $job->payload['properties']['account_id'] === (string) $this->account->id);
    Queue::assertNotPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === LifecycleEvent::ActivationReached->value);
});

test('an activation captures activation.reached with signup age and connected channels', function () {
    Queue::fake();
    SocialAccount::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);
    SocialAccount::factory()->disconnected()->create(['workspace_id' => $this->workspace->id]);
    $this->account->forceFill([
        'lifecycle_stage' => LifecycleStage::Active,
        'lifecycle_stage_changed_at' => now(),
        'activated_at' => now()->subDay(),
    ])->saveQuietly();

    (new TrackLifecycleChange((string) $this->account->id, LifecycleStage::Connected, LifecycleStage::Active, true))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === LifecycleEvent::ActivationReached->value
        && $job->payload['distinctId'] === (string) $this->owner->id
        && $job->payload['properties']['reached_at'] === now()->subDay()->toIso8601String()
        && $job->payload['properties']['days_since_signup'] === 4
        && $job->payload['properties']['channels_count'] === 2);
});

test('an activation without a stage change captures only activation.reached', function () {
    Queue::fake();
    $this->account->forceFill(['lifecycle_stage' => LifecycleStage::Churned, 'activated_at' => now()])->saveQuietly();

    (new TrackLifecycleChange((string) $this->account->id, LifecycleStage::Churned, LifecycleStage::Churned, true))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === LifecycleEvent::ActivationReached->value);
    Queue::assertNotPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === LifecycleEvent::StageChanged->value);
});

test('an account without an owner only updates the account group', function () {
    Queue::fake();
    $this->account->forceFill(['owner_id' => null, 'lifecycle_stage' => LifecycleStage::Dormant])->saveQuietly();

    (new TrackLifecycleChange((string) $this->account->id, LifecycleStage::AtRisk, LifecycleStage::Dormant, false))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'groupIdentify');
    Queue::assertNotPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method !== 'groupIdentify');
});

test('handle returns silently when the account is gone', function () {
    Queue::fake();

    (new TrackLifecycleChange((string) Str::uuid(), LifecycleStage::Active, LifecycleStage::AtRisk, false))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle does nothing when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Queue::fake();

    (new TrackLifecycleChange((string) $this->account->id, LifecycleStage::Active, LifecycleStage::AtRisk, false))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});
