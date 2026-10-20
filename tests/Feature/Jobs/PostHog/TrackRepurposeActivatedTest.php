<?php

declare(strict_types=1);

use App\Enums\PostHog\RepurposeEvent;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackRepurposeActivated;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    enablePostHog();
    Queue::fake([SendEvent::class]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id, 'user_id' => $this->user->id]);
    $this->source = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
});

test('job is queued on the posthog queue with retries and a timeout', function () {
    $job = new TrackRepurposeActivated((string) Str::uuid());

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('handle captures repurpose.activated for its creator with the source and destinations', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'source_social_account_id' => $this->source->id,
        'destinations' => [['social_account_id' => (string) Str::uuid()], ['social_account_id' => (string) Str::uuid()]],
    ]);

    (new TrackRepurposeActivated((string) $repurpose->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === RepurposeEvent::Activated->value
        && $job->payload['distinctId'] === (string) $this->user->id
        && $job->payload['properties']['repurpose_id'] === (string) $repurpose->id
        && $job->payload['properties']['source'] === Platform::Instagram->value
        && $job->payload['properties']['destinations_count'] === 2
        && $job->payload['properties']['$groups']['account'] === (string) $this->user->account_id);
});

test('handle captures nothing for a repurpose that is gone or has no creator', function () {
    $repurpose = Repurpose::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => null,
        'source_social_account_id' => $this->source->id,
    ]);

    (new TrackRepurposeActivated((string) $repurpose->id))->handle(app(PostHogService::class));
    (new TrackRepurposeActivated((string) Str::uuid()))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture');
});
