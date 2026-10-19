<?php

declare(strict_types=1);

use App\Enums\PostHog\WorkspaceEvent;
use App\Jobs\PostHog\CaptureAccountEvent;
use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
});

test('job is queued on the posthog queue', function () {
    $job = new CaptureAccountEvent('1', (string) Str::uuid(), WorkspaceEvent::MemberInvited, []);

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('captures the event with the account group', function () {
    Queue::fake();
    $account = Account::factory()->create();

    (new CaptureAccountEvent('user-1', (string) $account->id, WorkspaceEvent::MemberInvited, ['foo' => 'bar']))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['event'] === WorkspaceEvent::MemberInvited->value
        && $job->payload['distinctId'] === 'user-1'
        && $job->payload['properties']['foo'] === 'bar'
        && $job->payload['properties']['account_id'] === (string) $account->id
        && $job->payload['properties']['$groups']['account'] === (string) $account->id);
});

test('does nothing when the account is gone', function () {
    Queue::fake();

    (new CaptureAccountEvent('user-1', (string) Str::uuid(), WorkspaceEvent::MemberInvited, []))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});

test('does nothing when PostHog is disabled', function () {
    config(['services.posthog.api_key' => null]);
    Queue::fake();
    $account = Account::factory()->create();

    (new CaptureAccountEvent('user-1', (string) $account->id, WorkspaceEvent::MemberInvited, []))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
});
