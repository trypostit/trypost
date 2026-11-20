<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\PostHog\CheckoutEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackCheckoutExpired;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    enablePostHog();

    $this->account = Account::factory()->create(['plan_id' => null]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);

    $this->payload = [
        'id' => 'evt_expired',
        'type' => 'checkout.session.expired',
        'data' => ['object' => [
            'id' => 'cs_test_expired',
            'mode' => 'subscription',
            'customer' => 'cus_test',
            'metadata' => [
                'trypost_plan_slug' => 'socials',
                'trypost_interval' => 'monthly',
                'trypost_offer_slug' => 'bf26',
            ],
        ]],
    ];
});

test('job is queued on the posthog queue', function () {
    $job = new TrackCheckoutExpired((string) $this->account->id, $this->payload);

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('captures checkout.expired on the owner with plan and offer', function () {
    Queue::fake();

    (new TrackCheckoutExpired((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        $properties = $job->payload['properties'];

        return $job->method === 'capture'
            && $job->payload['event'] === CheckoutEvent::Expired->value
            && $job->payload['distinctId'] === (string) $this->user->id
            && $properties['$groups']['account'] === (string) $this->account->id
            && $properties['plan_name'] === Plan::where('slug', Slug::Socials)->value('name')
            && $properties['interval'] === 'monthly'
            && $properties['offer'] === 'bf26'
            && $properties['checkout_session_id'] === 'cs_test_expired';
    });
});

test('sends no offer when the session had none', function () {
    unset($this->payload['data']['object']['metadata']['trypost_offer_slug']);
    Queue::fake();

    (new TrackCheckoutExpired((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job): bool => $job->payload['properties']['offer'] === null,
    );
});

test('sends a null plan when the session carries an unknown plan slug', function () {
    $this->payload['data']['object']['metadata']['trypost_plan_slug'] = 'unknown';
    Queue::fake();

    (new TrackCheckoutExpired((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job): bool => $job->payload['properties']['plan_name'] === null,
    );
});

test('sends empty plan, interval and offer when the session has no metadata', function () {
    unset($this->payload['data']['object']['metadata']);
    Queue::fake();

    (new TrackCheckoutExpired((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        $properties = $job->payload['properties'];

        return $properties['plan_name'] === null
            && $properties['interval'] === null
            && $properties['offer'] === null;
    });
});

test('skips when the account is already subscribed', function (string $status, bool $onGracePeriod) {
    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_existing',
        'stripe_status' => $status,
        'stripe_price' => 'price_123',
        'trial_ends_at' => $status === 'trialing' ? now()->addDays(5) : null,
        'ends_at' => $onGracePeriod ? now()->addDays(5) : null,
    ]);
    Queue::fake();

    (new TrackCheckoutExpired((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNotPushed(SendEvent::class);
})->with([
    'active' => ['active', false],
    'trialing' => ['trialing', false],
    'past due' => ['past_due', false],
    'canceled on grace period' => ['active', true],
]);

test('returns silently when the account does not exist', function () {
    Queue::fake();

    (new TrackCheckoutExpired('00000000-0000-0000-0000-000000000000', $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});

test('returns silently when the account has no owner', function () {
    $this->account->update(['owner_id' => null]);
    Queue::fake();

    (new TrackCheckoutExpired((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});
