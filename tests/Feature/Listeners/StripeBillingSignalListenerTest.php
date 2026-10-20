<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Jobs\PostHog\TrackBilling;
use App\Jobs\PostHog\TrackCheckoutExpired;
use App\Jobs\PostHog\TrackInvoicePaymentFailed;
use App\Jobs\PostHog\TrackInvoiceUpcoming;
use App\Listeners\StripeBillingSignalListener;
use App\Listeners\StripeEventListener;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Cashier\Events\WebhookHandled;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(function () {
    enablePostHog();

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
                'trypost_first_month_offer' => 'true',
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

    Queue::assertPushedOnce(TrackCheckoutExpired::class);
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

test('upcoming invoice dispatches TrackInvoiceUpcoming', function () {
    Queue::fake();

    (new StripeBillingSignalListener)->handle(new WebhookReceived([
        'id' => 'evt_upcoming_1',
        'type' => 'invoice.upcoming',
        'data' => ['object' => ['customer' => 'cus_signal', 'amount_due' => 1900]],
    ]));

    Queue::assertPushed(
        TrackInvoiceUpcoming::class,
        fn (TrackInvoiceUpcoming $job): bool => $job->accountId === (string) $this->account->id,
    );
});

test('a dispatched WebhookReceived reaches the billing signal listener once', function () {
    Queue::fake();
    unset($this->expiredPayload['id']);

    event(new WebhookReceived($this->expiredPayload));

    Queue::assertPushedOnce(TrackCheckoutExpired::class);
});

test('a dispatched WebhookHandled reaches the subscription listener once', function () {
    Queue::fake();

    $plan = Plan::where('slug', Slug::Socials)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => 'price_socials_monthly_signal']);

    event(new WebhookHandled([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_signal',
            'customer' => 'cus_signal',
            'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_socials_monthly_signal']]]],
        ]],
    ]));

    Queue::assertPushedOnce(TrackBilling::class);
});

test('a failing dispatch is swallowed and Cashier still handles the webhook', function () {
    config(['cashier.webhook.secret' => null]);
    failDispatchOf(TrackInvoicePaymentFailed::class);
    Log::spy();

    $this->postJson(route('cashier.webhook'), [
        'id' => 'evt_payment_failed_dispatch',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => ['id' => 'in_failed', 'customer' => 'cus_signal']],
    ])->assertOk();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'Queue is down.'))->once();
});
