<?php

declare(strict_types=1);

use App\Enums\PostHog\SocialAccountEvent;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackSocialAccountConnected;
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

test('job is queued on the posthog queue with retries and a timeout', function () {
    $job = new TrackSocialAccountConnected((string) Str::uuid(), (string) Str::uuid(), 'app.workspace.channels');

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('handle captures social_account.connected with the channel properties', function () {
    Queue::fake();

    $socialAccount = SocialAccount::factory()->linkedinPage()->create(['workspace_id' => $this->workspace->id]);

    (new TrackSocialAccountConnected((string) $socialAccount->id, (string) $this->user->id, 'app.calendar'))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->method === 'capture'
        && $job->payload['event'] === SocialAccountEvent::Connected->value
        && $job->payload['distinctId'] === (string) $this->user->id
        && $job->payload['properties']['social_account_id'] === (string) $socialAccount->id
        && $job->payload['properties']['platform'] === Platform::LinkedInPage->value
        && $job->payload['properties']['network'] === Platform::LinkedInPage->network()
        && $job->payload['properties']['workspace_id'] === (string) $this->workspace->id
        && $job->payload['properties']['source'] === 'app.calendar'
        && $job->payload['properties']['nth_channel'] === 1
        && $job->payload['properties']['$groups']['account'] === (string) $this->account->id);
});

test('nth_channel counts channels across every workspace of the account', function () {
    Queue::fake();

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $foreignWorkspace = Workspace::factory()->create();

    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()->subDays(3)]);
    SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id, 'created_at' => now()->subDays(2)]);
    SocialAccount::factory()->create(['workspace_id' => $foreignWorkspace->id, 'created_at' => now()->subDays(2)]);
    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id, 'created_at' => now()->subDay()]);
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'created_at' => now()]);

    (new TrackSocialAccountConnected((string) $socialAccount->id, (string) $this->user->id, 'app.workspace.channels'))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->payload['properties']['nth_channel'] === 3);
});

test('handle returns silently when the channel is gone', function () {
    Queue::fake();

    (new TrackSocialAccountConnected((string) Str::uuid(), (string) $this->user->id, 'app.workspace.channels'))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle returns silently when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Queue::fake();

    $socialAccount = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    (new TrackSocialAccountConnected((string) $socialAccount->id, (string) $this->user->id, 'app.workspace.channels'))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('nth_channel breaks a created_at tie by id', function () {
    Queue::fake();

    $at = now()->startOfSecond();
    $first = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'created_at' => $at]);
    $second = SocialAccount::factory()->create(['workspace_id' => $this->workspace->id, 'created_at' => $at]);

    [$lower, $higher] = strcmp((string) $first->id, (string) $second->id) < 0 ? [$first, $second] : [$second, $first];

    (new TrackSocialAccountConnected((string) $lower->id, (string) $this->user->id, 'app.workspace.channels'))
        ->handle(app(PostHogService::class));
    (new TrackSocialAccountConnected((string) $higher->id, (string) $this->user->id, 'app.workspace.channels'))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->payload['properties']['social_account_id'] === (string) $lower->id
        && $job->payload['properties']['nth_channel'] === 1);
    Queue::assertPushed(SendEvent::class, fn (SendEvent $job): bool => $job->payload['properties']['social_account_id'] === (string) $higher->id
        && $job->payload['properties']['nth_channel'] === 2);
});
