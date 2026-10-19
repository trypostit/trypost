<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Jobs\PostHog\TrackCheckoutExpired;
use App\Jobs\PostHog\TrackInvoicePaymentFailed;
use App\Listeners\StripeBillingSignalListener;
use App\Listeners\StripeEventListener;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create(['stripe_id' => 'cus_signal']);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);

    $this->expiredPayload = [
        'id' => 'evt_expired_1',
        'type' => 'checkout.session.expired',
        'data' => ['object' => [
            'id' => 'cs_test_expired',
            'mode' => 'subscription',
            'customer' => 'cus_signal',
            'metadata' => [
                'trypost_plan_slug' => 'socials',
                'trypost_interval' => 'monthly',
                'trypost_first_month_offer' => '1',
            ],
        ]],
    ];
});

test('expired subscription checkout dispatches TrackCheckoutExpired', function () {
    Queue::fake();

    (new StripeBillingSignalListener)->handle(new WebhookReceived($this->expiredPayload));

    Queue::assertPushed(
        TrackCheckoutExpired::class,
        fn (TrackCheckoutExpired $job): bool => $job->accountId === (string) $this->account->id
            && data_get($job->payload, 'data.object.id') === 'cs_test_expired',
    );
});

test('same event id is handled once', function () {
    Queue::fake();
    $listener = new StripeBillingSignalListener;

    $listener->handle(new WebhookReceived($this->expiredPayload));
    $listener->handle(new WebhookReceived($this->expiredPayload));

    Queue::assertPushed(TrackCheckoutExpired::class, 1);
});

test('payment mode sessions are ignored', function () {
    Queue::fake();
    $this->expiredPayload['data']['object']['mode'] = 'payment';

    (new StripeBillingSignalListener)->handle(new WebhookReceived($this->expiredPayload));

    Queue::assertNotPushed(TrackCheckoutExpired::class);
});

test('expired checkout of an unknown customer is ignored', function () {
    Queue::fake();
    $this->expiredPayload['data']['object']['customer'] = 'cus_unknown';

    (new StripeBillingSignalListener)->handle(new WebhookReceived($this->expiredPayload));

    Queue::assertNotPushed(TrackCheckoutExpired::class);
});

test('expired checkout is not dispatched when posthog is off', function () {
    Queue::fake();
    config(['services.posthog.enabled' => false]);

    (new StripeBillingSignalListener)->handle(new WebhookReceived($this->expiredPayload));

    Queue::assertNotPushed(TrackCheckoutExpired::class);
});

test('subscription webhooks still reach StripeEventListener after WebhookReceived', function () {
    Queue::fake();

    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => 'price_socials_monthly_signal']);

    $payload = [
        'id' => 'evt_1',
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_signal',
            'customer' => 'cus_signal',
            'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_socials_monthly_signal']]]],
        ]],
    ];

    (new StripeBillingSignalListener)->handle(new WebhookReceived($payload));
    (new StripeEventListener)->handle(new WebhookHandled($payload));

    expect($this->account->fresh()->plan_id)->toBe($plan->id);
});

test('failed invoice payment dispatches TrackInvoicePaymentFailed', function () {
    Queue::fake();

    (new StripeBillingSignalListener)->handle(new WebhookReceived([
        'id' => 'evt_failed_1',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => ['id' => 'in_test_1', 'customer' => 'cus_signal', 'attempt_count' => 1]],
    ]));

    Queue::assertPushed(
        TrackInvoicePaymentFailed::class,
        fn (TrackInvoicePaymentFailed $job): bool => $job->accountId === (string) $this->account->id
            && data_get($job->payload, 'data.object.id') === 'in_test_1',
    );
});
