<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\PostHog\PlanEvent;
use App\Jobs\PostHog\SendEvent;
use App\Jobs\PostHog\TrackPlanChanged;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    Plan::query()->delete();
    $this->legacy = Plan::factory()->create(['slug' => Slug::Workspace, 'name' => 'Workspace', 'stripe_monthly_price_id' => 'price_legacy_monthly']);
    $this->socials = Plan::factory()->create(['slug' => Slug::Socials, 'stripe_monthly_price_id' => 'price_socials_monthly', 'stripe_yearly_price_id' => 'price_socials_yearly']);
    $this->workspaces = Plan::factory()->unlimited()->create(['slug' => Slug::Workspaces, 'name' => 'Workspaces', 'stripe_monthly_price_id' => 'price_workspaces_monthly']);

    $this->account = Account::factory()->create(['plan_id' => $this->workspaces->id]);
    $this->user = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->user->id]);
});

/**
 * @return array{id: string, type: string, data: array{object: array<string, mixed>, previous_attributes: array<string, mixed>}}
 */
function planChangePayload(string $fromId, int $fromAmount, string $fromInterval, string $toId, int $toAmount, string $toInterval, int $fromQuantity = 1): array
{
    return [
        'id' => 'evt_plan',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['items' => ['data' => [['quantity' => 1, 'price' => ['id' => $toId, 'unit_amount' => $toAmount, 'currency' => 'usd', 'recurring' => ['interval' => $toInterval]]]]]],
            'previous_attributes' => ['items' => ['data' => [['quantity' => $fromQuantity, 'price' => ['id' => $fromId, 'unit_amount' => $fromAmount, 'currency' => 'usd', 'recurring' => ['interval' => $fromInterval]]]]]],
        ],
    ];
}

/**
 * @param  array<string, mixed>  $payload
 */
function runPlanChanged(Account $account, array $payload): void
{
    (new TrackPlanChanged((string) $account->id, $payload))->handle(app(PostHogService::class));
}

test('job is queued on the posthog queue', function () {
    $job = new TrackPlanChanged((string) $this->account->id, []);

    expect($job->queue)->toBe('posthog')->and($job->tries)->toBe(3)->and($job->timeout)->toBe(30);
});

test('socials to workspaces is an upgrade even with the local plan already updated', function () {
    Queue::fake();

    runPlanChanged($this->account, planChangePayload('price_socials_monthly', 1900, 'month', 'price_workspaces_monthly', 9900, 'month'));

    Queue::assertPushed(SendEvent::class, function (SendEvent $job): bool {
        $properties = $job->payload['properties'];

        return $job->payload['event'] === PlanEvent::Upgraded->value
            && $job->payload['distinctId'] === (string) $this->user->id
            && $properties['plan_from'] === 'socials'
            && $properties['plan_to'] === 'workspaces'
            && $properties['interval_from'] === 'monthly'
            && $properties['interval_to'] === 'monthly'
            && $properties['monthly_amount_from'] === 19.0
            && $properties['monthly_amount_to'] === 99.0;
    });
});

test('workspaces to socials is a downgrade', function () {
    Queue::fake();

    runPlanChanged($this->account, planChangePayload('price_workspaces_monthly', 9900, 'month', 'price_socials_monthly', 1900, 'month'));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job): bool => $job->payload['event'] === PlanEvent::Downgraded->value
            && $job->payload['properties']['plan_from'] === 'workspaces'
            && $job->payload['properties']['plan_to'] === 'socials',
    );
});

test('legacy workspace to socials yearly is an upgrade', function () {
    Queue::fake();

    runPlanChanged($this->account, planChangePayload('price_legacy_monthly', 1200, 'month', 'price_socials_yearly', 19000, 'year'));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job): bool => $job->payload['event'] === PlanEvent::Upgraded->value
            && $job->payload['properties']['interval_to'] === 'yearly'
            && round($job->payload['properties']['monthly_amount_to'], 2) === 15.83,
    );
});

test('monthly to yearly on the same plan sends nothing', function () {
    Queue::fake();

    runPlanChanged($this->account, planChangePayload('price_socials_monthly', 1900, 'month', 'price_socials_yearly', 19000, 'year'));

    Queue::assertNotPushed(SendEvent::class);
});

test('sends nothing when a price is unknown', function () {
    Queue::fake();

    runPlanChanged($this->account, planChangePayload('price_unknown', 1900, 'month', 'price_workspaces_monthly', 9900, 'month'));

    Queue::assertNotPushed(SendEvent::class);
});

test('a legacy plan billed for three workspaces moving to socials is a downgrade', function () {
    Queue::fake();

    runPlanChanged($this->account, planChangePayload('price_legacy_monthly', 1200, 'month', 'price_socials_monthly', 1900, 'month', fromQuantity: 3));

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job): bool => $job->payload['event'] === PlanEvent::Downgraded->value
            && $job->payload['properties']['monthly_amount_from'] === 36.0
            && $job->payload['properties']['monthly_amount_to'] === 19.0,
    );
});

test('sends null amounts when the payload has no unit amount', function () {
    Queue::fake();

    $payload = planChangePayload('price_socials_monthly', 1900, 'month', 'price_workspaces_monthly', 9900, 'month');
    data_forget($payload, 'data.object.items.data.0.price.unit_amount');
    data_forget($payload, 'data.previous_attributes.items.data.0.price.unit_amount');

    runPlanChanged($this->account, $payload);

    Queue::assertPushed(
        SendEvent::class,
        fn (SendEvent $job): bool => $job->payload['event'] === PlanEvent::Downgraded->value
            && $job->payload['properties']['monthly_amount_from'] === null
            && $job->payload['properties']['monthly_amount_to'] === null,
    );
});

test('the direction does not depend on the price currency', function () {
    Queue::fake();
    $payload = planChangePayload('price_socials_monthly', 1900, 'month', 'price_workspaces_monthly', 9900, 'month');
    unset($payload['data']['object']['items']['data'][0]['price']['currency'], $payload['data']['previous_attributes']['items']['data'][0]['price']['currency']);

    runPlanChanged($this->account, $payload);

    Queue::assertPushed(SendEvent::class, fn (SendEvent $job) => $job->payload['event'] === PlanEvent::Upgraded->value);
});
