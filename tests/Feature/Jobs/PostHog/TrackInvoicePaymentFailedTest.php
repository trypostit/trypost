<?php

declare(strict_types=1);

use App\Enums\PostHog\InvoiceEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackInvoicePaymentFailed;
use App\Models\Account;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $this->account = Account::factory()->create(['plan_id' => null]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);

    $this->payload = [
        'id' => 'evt_failed',
        'type' => 'invoice.payment_failed',
        'data' => ['object' => [
            'id' => 'in_test_1',
            'customer' => 'cus_test',
            'attempt_count' => 2,
            'amount_due' => 1900,
            'currency' => 'usd',
            'billing_reason' => 'subscription_cycle',
            'next_payment_attempt' => 1792000000,
        ]],
    ];
});

test('job is queued on the posthog queue', function () {
    $job = new TrackInvoicePaymentFailed((string) $this->account->id, $this->payload);

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('captures invoice.payment_failed with attempt and amount', function () {
    Queue::fake();

    (new TrackInvoicePaymentFailed((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        $properties = $job->payload['properties'];

        return $job->payload['event'] === InvoiceEvent::PaymentFailed->value
            && $job->payload['distinctId'] === (string) $this->user->id
            && $properties['$groups']['account'] === (string) $this->account->id
            && $properties['attempt_count'] === 2
            && $properties['amount'] === 19.0
            && $properties['currency'] === 'USD'
            && $properties['next_attempt_at'] === '2026-10-14T17:46:40+00:00'
            && $properties['is_final_attempt'] === false
            && $properties['billing_reason'] === 'subscription_cycle'
            && $properties['invoice_id'] === 'in_test_1';
    });
});

test('marks the final attempt when Stripe schedules no retry', function () {
    $this->payload['data']['object']['next_payment_attempt'] = null;
    Queue::fake();

    (new TrackInvoicePaymentFailed((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        $properties = $job->payload['properties'];

        return $properties['is_final_attempt'] === true && $properties['next_attempt_at'] === null;
    });
});

test('returns silently when the account has no owner', function () {
    $this->account->update(['owner_id' => null]);
    Queue::fake();

    (new TrackInvoicePaymentFailed((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});
