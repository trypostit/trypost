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
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key', 'trypost.self_hosted' => false]);
    Queue::fake([SendEvent::class]);
});

test('job is queued on the posthog queue with retries and a timeout', function () {
    $job = new TrackWorkspaceLimitHit((string) Str::uuid(), Slug::Socials->value, 1, now());

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('handle captures workspace.limit_hit with the plan and the account workspaces', function () {
    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::query()->where('slug', Slug::Socials)->value('id')]);
    Workspace::factory()->count(2)->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    (new TrackWorkspaceLimitHit((string) $user->id, Slug::Socials->value, 1, now()))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === WorkspaceEvent::LimitHit->value
        && $job->payload['distinctId'] === (string) $user->id
        && $job->payload['properties']['plan_slug'] === Slug::Socials->value
        && $job->payload['properties']['workspaces_count'] === $user->account->workspaces()->count()
        && $job->payload['properties']['workspace_limit'] === 1
        && $job->payload['properties']['$groups']['account'] === (string) $user->account_id);
});

test('handle reports the plan, limit and workspaces the refusal saw, even after an upgrade before the job ran', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $user->account->update(['plan_id' => Plan::query()->where('slug', Slug::Socials)->value('id')]);
    $workspacesAtHit = $user->account->workspaces()->count();
    $job = new TrackWorkspaceLimitHit((string) $user->id, Slug::Socials->value, 1, now());

    $this->travel(1)->minute();
    $user->account->update(['plan_id' => Plan::query()->where('slug', Slug::Workspaces)->value('id')]);
    Workspace::factory()->create(['account_id' => $user->account_id, 'user_id' => $user->id]);

    $job->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['properties']['plan_slug'] === Slug::Socials->value
        && $job->payload['properties']['workspace_limit'] === 1
        && $job->payload['properties']['workspaces_count'] === $workspacesAtHit);
});

test('handle captures nothing for a user that is gone', function () {
    (new TrackWorkspaceLimitHit((string) Str::uuid(), Slug::Socials->value, 1, now()))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture');
});
