<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\PostHog\IdentifyConnectedPlatforms;
use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
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
});

test('job is queued on the posthog queue', function () {
    $job = new IdentifyConnectedPlatforms((string) Str::uuid());

    expect($job->queue)->toBe('posthog');
});

test('handle identifies the owner with connected platforms', function () {
    Queue::fake();

    SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    (new IdentifyConnectedPlatforms((string) $this->workspace->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        return $job->method === 'identify'
            && data_get($job->payload, 'distinctId') === $this->user->id
            && data_get($job->payload, 'properties.connected_platforms') === [Platform::LinkedIn->value];
    });
});

test('handle identifies the owner without disconnected platforms', function () {
    Queue::fake();

    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $this->workspace->id,
        'status' => Status::Disconnected,
    ]);

    (new IdentifyConnectedPlatforms((string) $this->workspace->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        return $job->method === 'identify'
            && data_get($job->payload, 'distinctId') === $this->user->id
            && data_get($job->payload, 'properties.connected_platforms') === [];
    });
});

test('handle returns silently when the workspace does not exist', function () {
    Queue::fake();

    (new IdentifyConnectedPlatforms((string) Str::uuid()))->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle does not push when PostHog is disabled', function () {
    config(['services.posthog.api_key' => null]);
    Queue::fake();

    SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    (new IdentifyConnectedPlatforms((string) $this->workspace->id))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});
