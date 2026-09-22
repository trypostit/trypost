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
    config(['trypost.self_hosted' => false]);

    $this->account = Account::factory()->create([
        'plan_id' => Plan::query()->where('slug', 'workspace')->first()?->id,
    ]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
    ]);

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

test('handle updates the account group with the current subscription state', function () {
    Queue::fake();

    (new TrackBilling((string) $this->account->id, BillingEvent::Updated, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        return $job->method === 'groupIdentify'
            && $job->payload['groupType'] === 'account'
            && $job->payload['groupKey'] === (string) $this->account->id
            && $job->payload['properties']['subscription_status'] === 'active'
            && $job->payload['properties']['has_active_subscription'] === true
            && ! array_key_exists('first_month_offer_ends_at', $job->payload['properties']);
    });
});

test('subscription creation sets the first month offer end on the account group', function () {
    $periodEndsAt = now()->addMonth()->startOfSecond();
    $this->payload['data']['object']['metadata'] = [
        'trypost_first_month_coupon_id' => 'WORKSPACES_88USD',
    ];
    $this->payload['data']['object']['items']['data'][0]['current_period_end'] = $periodEndsAt->timestamp;
    Queue::fake();

    (new TrackBilling((string) $this->account->id, BillingEvent::Created, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job) use ($periodEndsAt): bool {
        return $job->method === 'groupIdentify'
            && $job->payload['properties']['first_month_offer_ends_at'] === $periodEndsAt->toIso8601String();
    });
});

test('subscription creation clears a previous first month offer when the new subscription has none', function () {
    Queue::fake();

    (new TrackBilling((string) $this->account->id, BillingEvent::Created, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        return $job->method === 'groupIdentify'
            && array_key_exists('first_month_offer_ends_at', $job->payload['properties'])
            && $job->payload['properties']['first_month_offer_ends_at'] === null;
    });
});

test('subscription cancellation updates the account group after Cashier marks it ended', function () {
    $this->account->subscription()->markAsCanceled();
    $this->payload['data']['object']['status'] = 'canceled';
    Queue::fake();

    (new TrackBilling((string) $this->account->id, BillingEvent::Cancelled, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        return $job->method === 'groupIdentify'
            && $job->payload['properties']['subscription_status'] === 'canceled'
            && $job->payload['properties']['has_active_subscription'] === false;
    });
});

test('scheduled cancellation is canceled while access remains active during the grace period', function () {
    $this->account->subscription()->update(['ends_at' => now()->addWeek()]);
    Queue::fake();

    (new TrackBilling((string) $this->account->id, BillingEvent::Updated, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        return $job->method === 'groupIdentify'
            && $job->payload['properties']['subscription_status'] === 'canceled'
            && $job->payload['properties']['has_active_subscription'] === true;
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
        return $job->method === 'capture'
            && $job->payload['properties']['previous_plan'] === 'Starter';
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

    Log::shouldReceive('info')->once()->withArgs(fn ($message) => $message === 'PostHogService: capture');
    Log::shouldReceive('info')->once()->withArgs(fn ($message) => $message === 'PostHogService: groupIdentify');

    (new TrackBilling((string) $this->account->id, BillingEvent::Created, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
    Bus::assertDispatched(SyncUser::class);
});
