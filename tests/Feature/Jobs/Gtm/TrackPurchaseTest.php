<?php

declare(strict_types=1);

use App\Enums\User\Persona;
use App\Jobs\Gtm\SendServerEvent;
use App\Jobs\Gtm\TrackPurchase;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\GtmServerService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config([
        'services.gtm.backend.enabled' => true,
        'services.gtm.backend.endpoint' => 'https://sgtm.test/collect',
    ]);

    $this->plan = Plan::where('slug', 'workspace')->firstOrFail();
    $this->plan->update([
        'stripe_monthly_price_id' => 'price_workspace_monthly',
        'stripe_yearly_price_id' => 'price_workspace_yearly',
    ]);

    $this->account = Account::factory()->create(['plan_id' => $this->plan->id]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);

    $this->payload = [
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_test123',
            'customer' => 'cus_test123',
            'items' => ['data' => [[
                'price' => [
                    'id' => 'price_workspace_monthly',
                    'unit_amount' => 2900,
                    'currency' => 'usd',
                ],
            ]]],
        ]],
    ];
});

test('job is queued on the gtm queue', function () {
    $job = new TrackPurchase((string) $this->account->id, $this->payload);

    expect($job->queue)->toBe('gtm');
});

test('handle captures a purchase event with plan, interval and conversion data', function () {
    Queue::fake();

    (new TrackPurchase((string) $this->account->id, $this->payload))
        ->handle(app(GtmServerService::class));

    Queue::assertPushed(SendServerEvent::class, function (SendServerEvent $job) {
        return $job->event === 'purchase'
            && $job->distinctId === (string) $this->user->id
            && $job->properties['plan_name'] === $this->plan->name
            && $job->properties['plan_interval'] === 'monthly'
            && $job->properties['conversion_value'] === 29.0
            && $job->properties['conversion_currency'] === 'USD'
            && $job->properties['conversion_transaction_id'] === 'sub_test123';
    });
});

test('handle resolves the yearly interval from the price id', function () {
    $this->payload['data']['object']['items']['data'][0]['price']['id'] = 'price_workspace_yearly';
    Queue::fake();

    (new TrackPurchase((string) $this->account->id, $this->payload))
        ->handle(app(GtmServerService::class));

    Queue::assertPushed(
        SendServerEvent::class,
        fn (SendServerEvent $job) => $job->properties['plan_interval'] === 'yearly',
    );
});

test('handle forwards the owner persona as an event property', function () {
    $this->user->update(['persona' => Persona::Agency->value]);
    Queue::fake();

    (new TrackPurchase((string) $this->account->id, $this->payload))
        ->handle(app(GtmServerService::class));

    Queue::assertPushed(
        SendServerEvent::class,
        fn (SendServerEvent $job) => $job->properties['persona'] === Persona::Agency->value,
    );
});

test('handle omits conversion fields when the webhook payload has no price amount', function () {
    unset($this->payload['data']['object']['items']['data'][0]['price']['unit_amount']);
    Queue::fake();

    (new TrackPurchase((string) $this->account->id, $this->payload))
        ->handle(app(GtmServerService::class));

    Queue::assertPushed(SendServerEvent::class, function (SendServerEvent $job) {
        return ! array_key_exists('conversion_value', $job->properties)
            && ! array_key_exists('conversion_currency', $job->properties)
            && ! array_key_exists('conversion_transaction_id', $job->properties);
    });
});

test('handle returns silently when account does not exist', function () {
    Queue::fake();

    (new TrackPurchase('00000000-0000-0000-0000-000000000000', $this->payload))
        ->handle(app(GtmServerService::class));

    Queue::assertNothingPushed();
});

test('handle returns silently when account has no owner', function () {
    $this->account->update(['owner_id' => null]);
    Queue::fake();

    (new TrackPurchase((string) $this->account->id, $this->payload))
        ->handle(app(GtmServerService::class));

    Queue::assertNothingPushed();
});

test('handle returns silently when account has no plan', function () {
    $this->account->update(['plan_id' => null]);
    Queue::fake();

    (new TrackPurchase((string) $this->account->id, $this->payload))
        ->handle(app(GtmServerService::class));

    Queue::assertNothingPushed();
});

test('handle does not push when the GTM backend container is disabled', function () {
    config(['services.gtm.backend.enabled' => false]);
    Queue::fake();

    (new TrackPurchase((string) $this->account->id, $this->payload))
        ->handle(app(GtmServerService::class));

    Queue::assertNothingPushed();
});
