<?php

declare(strict_types=1);

use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Jobs\PostHog\SyncUser;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create([
        'plan_id' => Plan::query()->where('slug', 'workspace')->first()?->id,
    ]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
});

test('handle is a no-op when api key is unset', function () {
    config(['services.posthog.api_key' => null]);
    Queue::fake();

    (new SyncUser((string) $this->user->id))->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle returns silently when user does not exist', function () {
    Queue::fake();

    (new SyncUser((string) Str::uuid()))->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('handle identifies the user with email, name and signed_up_at', function () {
    Queue::fake();

    (new SyncUser((string) $this->user->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) {
        return $job->method === 'identify'
            && $job->payload['distinctId'] === (string) $this->user->id
            && $job->payload['properties']['$email'] === $this->user->email
            && $job->payload['properties']['$name'] === $this->user->name
            && isset($job->payload['properties']['$set_once']['signed_up_at']);
    });
});

test('handle forwards ad click ids as first-touch person properties when present', function () {
    $this->user->update(['gclid' => 'g-123', 'fbclid' => 'fb-456']);
    Queue::fake();

    (new SyncUser((string) $this->user->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) {
        return $job->payload['properties']['$set_once']['gclid'] === 'g-123'
            && $job->payload['properties']['$set_once']['fbclid'] === 'fb-456';
    });
});

test('handle forwards utm parameters as first-touch person properties when present', function () {
    $this->user->update(['utm_source' => 'google', 'utm_medium' => 'cpc', 'utm_campaign' => 'spring-launch']);
    Queue::fake();

    (new SyncUser((string) $this->user->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) {
        return $job->payload['properties']['$set_once']['utm_source'] === 'google'
            && $job->payload['properties']['$set_once']['utm_medium'] === 'cpc'
            && $job->payload['properties']['$set_once']['utm_campaign'] === 'spring-launch';
    });
});

test('handle omits attribution properties when none are set', function () {
    Queue::fake();

    (new SyncUser((string) $this->user->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) {
        $setOnce = $job->payload['properties']['$set_once'];

        return ! array_key_exists('utm_source', $setOnce)
            && ! array_key_exists('utm_medium', $setOnce)
            && ! array_key_exists('utm_campaign', $setOnce)
            && ! array_key_exists('utm_term', $setOnce)
            && ! array_key_exists('utm_content', $setOnce)
            && ! array_key_exists('gclid', $setOnce)
            && ! array_key_exists('fbclid', $setOnce)
            && ! array_key_exists('li_fat_id', $setOnce)
            && ! array_key_exists('ttclid', $setOnce)
            && ! array_key_exists('rdt_cid', $setOnce)
            && ! array_key_exists('epik', $setOnce);
    });
});

test('handle dispatches SyncAccountUsage with the user account id', function () {
    Queue::fake();

    (new SyncUser((string) $this->user->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SyncAccountUsage::class, function ($job) {
        return $job->accountId === (string) $this->account->id
            && $job->workspaceId === null;
    });
});

test('handle dispatches SyncAccountUsage with the current workspace when set', function () {
    $workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $workspace->id]);

    Queue::fake();

    (new SyncUser((string) $this->user->id))->handle(app(PostHogService::class));

    Queue::assertPushed(SyncAccountUsage::class, function ($job) use ($workspace) {
        return $job->accountId === (string) $this->account->id
            && $job->workspaceId === (string) $workspace->id;
    });
});

test('job is queued on the posthog connection queue', function () {
    $job = new SyncUser((string) $this->user->id);

    expect($job->queue)->toBe('posthog');
});
