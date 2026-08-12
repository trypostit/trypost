<?php

declare(strict_types=1);

use App\Enums\PostHog\BillingEvent;
use App\Enums\User\Persona;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\SyncUser;
use App\Jobs\PostHog\TrackBilling;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create([
        'plan_id' => Plan::query()->where('slug', 'workspace')->first()?->id,
    ]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);

    $this->payload = [
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['customer' => 'cus_test', 'status' => 'active']],
    ];
});

test('job is queued on the posthog queue', function () {
    $job = new TrackBilling((string) $this->account->id, BillingEvent::Updated, $this->payload);

    expect($job->queue)->toBe('posthog');
});

test('handle captures event on the owner profile with account group attached', function () {
    Queue::fake();

    (new TrackBilling((string) $this->account->id, BillingEvent::Updated, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) {
        return $job->method === 'capture'
            && $job->payload['event'] === BillingEvent::Updated->value
            && $job->payload['distinctId'] === (string) $this->user->id
            && $job->payload['properties']['$groups']['account'] === (string) $this->account->id
            && $job->payload['properties']['stripe_status'] === 'active'
            && array_key_exists('previous_plan', $job->payload['properties']);
    });
});

test('handle does not forward persona — it is already an identified person property', function () {
    $this->user->update(['persona' => Persona::Agency->value]);
    Queue::fake();

    (new TrackBilling((string) $this->account->id, BillingEvent::Created, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(
        SendEvent::class,
        fn ($job) => ! array_key_exists('persona', $job->payload['properties']),
    );
});

test('handle forwards previousPlan as a property when supplied', function () {
    Queue::fake();

    (new TrackBilling((string) $this->account->id, BillingEvent::Updated, $this->payload, 'Starter'))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function ($job) {
        return $job->payload['properties']['previous_plan'] === 'Starter';
    });
});

test('handle dispatches SyncUser for the account owner', function () {
    Bus::fake([SyncUser::class]);

    (new TrackBilling((string) $this->account->id, BillingEvent::Cancelled, $this->payload))
        ->handle(app(PostHogService::class));

    Bus::assertDispatched(
        SyncUser::class,
        fn ($job) => $job->userId === (string) $this->user->id,
    );
});

test('handle returns silently when account does not exist', function () {
    Queue::fake();
    Bus::fake([SyncUser::class]);

    (new TrackBilling('00000000-0000-0000-0000-000000000000', BillingEvent::Created, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
    Bus::assertNotDispatched(SyncUser::class);
});

test('handle returns silently when account has no owner', function () {
    $this->account->update(['owner_id' => null]);
    Queue::fake();
    Bus::fake([SyncUser::class]);

    (new TrackBilling((string) $this->account->id, BillingEvent::Created, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
    Bus::assertNotDispatched(SyncUser::class);
});

test('handle does not push a PostHog network call when api key is unset', function () {
    config(['services.posthog.api_key' => null]);
    Queue::fake();

    (new TrackBilling((string) $this->account->id, BillingEvent::Created, $this->payload))
        ->handle(app(PostHogService::class));

    // The contract of this job is: when PostHog is disabled, handle short-
    // circuits before any DB query and no SendEvent reaches the queue.
    Queue::assertNotPushed(SendEvent::class);
});

test('handle does not push a PostHog network call when disabled in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Queue::fake();
    Bus::fake([SyncUser::class]);

    (new TrackBilling((string) $this->account->id, BillingEvent::Created, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
    Bus::assertNotDispatched(SyncUser::class);
});

test('handle logs locally but still does not push a PostHog network call in the local environment when disabled', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Queue::fake();
    Bus::fake([SyncUser::class]);

    Log::shouldReceive('info')->once()->withArgs(
        fn ($message) => $message === 'PostHogService: capture',
    );

    (new TrackBilling((string) $this->account->id, BillingEvent::Created, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
    Bus::assertDispatched(SyncUser::class);
});
