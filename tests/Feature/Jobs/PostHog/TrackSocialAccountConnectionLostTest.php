<?php

declare(strict_types=1);

use App\Enums\PostHog\SocialAccountEvent;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackSocialAccountConnectionLost;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    enablePostHog();
    Queue::fake([SendEvent::class]);
});

test('job is queued on the posthog queue with retries and a timeout', function () {
    $job = new TrackSocialAccountConnectionLost((string) Str::uuid(), Status::TokenExpired);

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('handle captures social_account.disconnected for the account owner with the status as reason and error type', function () {
    $owner = User::factory()->create();
    $owner->account->update(['owner_id' => $owner->id]);
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $socialAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    (new TrackSocialAccountConnectionLost((string) $socialAccount->id, Status::TokenExpired))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === SocialAccountEvent::Disconnected->value
        && $job->payload['distinctId'] === (string) $owner->id
        && $job->payload['properties']['social_account_id'] === (string) $socialAccount->id
        && $job->payload['properties']['platform'] === Platform::X->value
        && $job->payload['properties']['network'] === Platform::X->network()
        && $job->payload['properties']['workspace_id'] === (string) $workspace->id
        && $job->payload['properties']['reason'] === Status::TokenExpired->value
        && $job->payload['properties']['error_type'] === Status::TokenExpired->value
        && $job->payload['properties']['$groups']['account'] === (string) $owner->account_id);
});

test('handle captures nothing for a channel that is gone', function () {
    (new TrackSocialAccountConnectionLost((string) Str::uuid(), Status::Disconnected))->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture');
});
