<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\PostHog\InvoiceEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackInvoiceUpcoming;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    Plan::query()->delete();
    Plan::factory()->create(['slug' => Slug::Socials, 'stripe_monthly_price_id' => 'price_socials_monthly', 'stripe_yearly_price_id' => 'price_socials_yearly']);

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
            'lines' => ['data' => [invoiceLine('price_socials_monthly', proration: false)]],
        ]],
    ];
});

/**
 * @return array<string, mixed>
 */
function invoiceLine(string $priceId, bool $proration): array
{
    return [
        'period' => ['start' => 1792000000, 'end' => 1794592000],
        'pricing' => ['type' => 'price_details', 'price_details' => ['price' => $priceId, 'product' => 'prod_socials']],
        'parent' => ['type' => 'subscription_item_details', 'subscription_item_details' => [
            'proration' => $proration,
            'subscription' => 'sub_upcoming',
            'subscription_item' => 'si_upcoming',
        ]],
    ];
}

function createSubscriptionStartedAt(Account $account, string $startedAt, string $priceId = 'price_socials_monthly'): void
{
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_upcoming',
        'stripe_status' => 'active',
        'stripe_price' => $priceId,
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
            && $properties['interval'] === 'monthly'
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

test('ignores a proration line ahead of the subscription line', function () {
    createSubscriptionStartedAt($this->account, '2025-10-20', 'price_socials_yearly');
    Queue::fake();

    $proration = invoiceLine('price_socials_monthly', proration: true);
    $proration['period'] = ['start' => 1780000000, 'end' => 1781000000];
    data_set($this->payload, 'data.object.lines.data', [$proration, invoiceLine('price_socials_yearly', proration: false)]);
    data_set($this->payload, 'data.object.next_payment_attempt', null);

    (new TrackInvoiceUpcoming((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        $properties = $job->payload['properties'];

        return $properties['interval'] === 'yearly'
            && $properties['charge_at'] === '2026-10-14T17:46:40+00:00'
            && $properties['period_end'] === '2026-11-13T17:46:40+00:00'
            && $properties['is_first_full_charge'] === true;
    });
});

test('falls back to the local subscription price without a subscription line', function () {
    createSubscriptionStartedAt($this->account, '2025-10-20', 'price_socials_yearly');
    Queue::fake();

    data_set($this->payload, 'data.object.lines.data', []);

    (new TrackInvoiceUpcoming((string) $this->account->id, $this->payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job): bool => $job->payload['properties']['interval'] === 'yearly'
            && $job->payload['properties']['period_end'] === null,
    );
});

test('sends nulls when lines, currency and the next attempt are missing', function () {
    Queue::fake();

    $payload = ['id' => 'evt_bare', 'type' => 'invoice.upcoming', 'data' => ['object' => ['customer' => 'cus_test']]];

    (new TrackInvoiceUpcoming((string) $this->account->id, $payload))
        ->handle(app(PostHogService::class));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        $properties = $job->payload['properties'];

        return $properties['amount'] === null
            && $properties['currency'] === null
            && $properties['charge_at'] === null
            && $properties['period_end'] === null
            && $properties['interval'] === null
            && $properties['is_first_full_charge'] === null;
    });
});
