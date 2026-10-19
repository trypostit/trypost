<?php

declare(strict_types=1);

use App\Enums\PostHog\InvoiceEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackInvoiceUpcoming;
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
        'id' => 'evt_upcoming',
        'type' => 'invoice.upcoming',
        'data' => ['object' => [
            'customer' => 'cus_test',
            'amount_due' => 1900,
            'currency' => 'usd',
            'next_payment_attempt' => 1792000000,
            'lines' => ['data' => [[
                'period' => ['end' => 1794592000],
                'price' => ['recurring' => ['interval' => 'month']],
            ]]],
        ]],
    ];
});

function createSubscriptionStartedAt(Account $account, string $startedAt): void
{
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_upcoming',
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
        'created_at' => $startedAt,
    ]);
}

test('job is queued on the posthog queue', function () {
    $job = new TrackInvoiceUpcoming((string) $this->account->id, $this->payload);

    expect($job->queue)->toBe('posthog')
        ->and($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(30);
});

test('captures invoice.upcoming with amount and first charge flag', function () {
    createSubscriptionStartedAt($this->account, '2026-09-20');
    Queue::fake();

    (new TrackInvoiceUpcoming((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        $properties = $job->payload['properties'];

        return $job->payload['event'] === InvoiceEvent::Upcoming->value
            && $job->payload['distinctId'] === (string) $this->user->id
            && $properties['$groups']['account'] === (string) $this->account->id
            && $properties['amount'] === 19.0
            && $properties['currency'] === 'USD'
            && $properties['charge_at'] === '2026-10-14T17:46:40+00:00'
            && $properties['period_end'] === '2026-11-13T17:46:40+00:00'
            && $properties['interval'] === 'month'
            && $properties['is_first_full_charge'] === true;
    });
});

test('flags a later renewal as not the first full charge', function () {
    createSubscriptionStartedAt($this->account, '2026-06-01');
    Queue::fake();

    (new TrackInvoiceUpcoming((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job): bool => $job->payload['properties']['is_first_full_charge'] === false,
    );
});

test('sends a null first charge flag without a local subscription', function () {
    Queue::fake();

    (new TrackInvoiceUpcoming((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job): bool => $job->payload['properties']['is_first_full_charge'] === null,
    );
});

test('returns silently when the account has no owner', function () {
    $this->account->update(['owner_id' => null]);
    Queue::fake();

    (new TrackInvoiceUpcoming((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertNothingPushed();
});
