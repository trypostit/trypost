<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\PostHog\WorkspaceEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackWorkspaceLimitHit;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key', 'trypost.self_hosted' => false]);
});

$onPlan = function (Slug $slug): User {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::where('slug', $slug)->value('id')]);
    Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);
    subscribeAccount($user->account);

    return $user->fresh();
};

test('job is queued on the posthog queue', function () {
    $job = new TrackWorkspaceLimitHit('1');

    expect($job->queue)->toBe('posthog')->and($job->tries)->toBe(3)->and($job->timeout)->toBe(30);
});

test('captures limit_hit at the workspace cap', function () use ($onPlan) {
    Queue::fake();
    $user = $onPlan(Slug::Socials);

    (new TrackWorkspaceLimitHit((string) $user->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['event'] === WorkspaceEvent::LimitHit->value
        && $job->payload['distinctId'] === (string) $user->id
        && $job->payload['properties']['plan_slug'] === Slug::Socials->value
        && $job->payload['properties']['workspaces_count'] === 1
        && $job->payload['properties']['workspace_limit'] === 1
        && $job->payload['properties']['account_id'] === (string) $user->account_id);
});

test('sends nothing below the workspace cap', function () use ($onPlan) {
    Queue::fake();
    $user = $onPlan(Slug::Workspaces);

    (new TrackWorkspaceLimitHit((string) $user->id))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});
