<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Enums\PostHog\BillingEvent;
use App\Jobs\PostHog\TrackBilling;
use App\Jobs\PostHog\TrackCheckoutCompleted;
use App\Jobs\PostHog\TrackTrialConverted;
use App\Jobs\PostHog\TrackTrialStarted;
use App\Listeners\StripeEventListener;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    // Use the seeded Workspace plan; set deterministic price ids so the
    // assertions don't depend on `.env.testing`.
    $this->plan = Plan::where('slug', Slug::Workspace)->firstOrFail();
    $this->plan->update([
        'stripe_monthly_price_id' => 'price_workspace_monthly',
        'stripe_yearly_price_id' => 'price_workspace_yearly',
    ]);

    $this->account = Account::factory()->create(['stripe_id' => 'cus_test123']);
    $this->user = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->user->id]);

    $this->listener = new StripeEventListener;
});

// ========================================
// customer.subscription.created
// ========================================

test('subscription created handles event without error', function () {
    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['customer' => 'cus_test123', 'id' => 'sub_123']],
    ]));

    expect(true)->toBeTrue();
});

test('subscription created clears the generic trial_ends_at on the account', function () {
    $this->account->update(['trial_ends_at' => now()->addDays(3)]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'id' => 'sub_123',
            'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    expect($this->account->fresh()->trial_ends_at)->toBeNull();
});

// ========================================
// customer.subscription.updated
// ========================================

test('subscription updated handles event without error', function () {
    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['customer' => 'cus_test123', 'id' => 'sub_123']],
    ]));

    expect(true)->toBeTrue();
});

// ========================================
// customer.subscription.deleted
// ========================================

test('subscription deleted handles event without error', function () {
    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['customer' => 'cus_test123', 'id' => 'sub_123']],
    ]));

    expect(true)->toBeTrue();
});

// ========================================
// Edge cases — missing/invalid data
// ========================================

test('ignores event without customer id', function () {
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => []],
    ]));

    Bus::assertNotDispatched(TrackBilling::class);
});

test('ignores event for unknown customer', function () {
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['customer' => 'cus_nonexistent']],
    ]));

    Bus::assertNotDispatched(TrackBilling::class);
});

test('ignores event with empty payload', function () {
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([]));

    Bus::assertNotDispatched(TrackBilling::class);
});

test('ignores event with null type', function () {
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => null,
        'data' => ['object' => ['customer' => 'cus_test123']],
    ]));

    Bus::assertNotDispatched(TrackBilling::class);
});

// ========================================
// Error handling
// ========================================

test('handles malformed payload gracefully', function () {
    $this->listener->handle(new WebhookReceived([
        'data' => 'not_an_array',
    ]));

    expect(true)->toBeTrue();
});

// ========================================
// PostHog tracking — listener delegates to TrackBilling
// ========================================

test('subscription created dispatches TrackBilling with subscription.created event', function () {
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'trialing']],
    ]));

    Bus::assertDispatched(
        TrackBilling::class,
        fn ($job) => $job->accountId === (string) $this->account->id
            && $job->event === BillingEvent::Created,
    );
});

test('subscription updated dispatches TrackBilling with subscription.updated event', function () {
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => ['customer' => 'cus_test123', 'status' => 'active']],
    ]));

    Bus::assertDispatched(
        TrackBilling::class,
        fn ($job) => $job->event === BillingEvent::Updated,
    );
});

test('subscription deleted dispatches TrackBilling with subscription.cancelled event', function () {
    $this->account->update(['plan_id' => $this->plan->id]);

    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['customer' => 'cus_test123', 'status' => 'canceled']],
    ]));

    Bus::assertDispatched(
        TrackBilling::class,
        fn ($job) => $job->event === BillingEvent::Cancelled,
    );
});

test('unknown event types do not dispatch TrackBilling', function () {
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'invoice.payment_succeeded',
        'data' => ['object' => ['customer' => 'cus_test123']],
    ]));

    Bus::assertNotDispatched(TrackBilling::class);
});

test('TrackBilling is not dispatched when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['customer' => 'cus_test123', 'id' => 'sub_123']],
    ]));

    Bus::assertNotDispatched(TrackBilling::class);
});

test('TrackBilling is not dispatched when PostHog is disabled in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['customer' => 'cus_test123', 'id' => 'sub_123']],
    ]));

    Bus::assertNotDispatched(TrackBilling::class);
});

test('TrackBilling is dispatched in the local environment even when PostHog is disabled', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['customer' => 'cus_test123', 'id' => 'sub_123']],
    ]));

    Bus::assertDispatched(TrackBilling::class);
});

test('TrackBilling is not dispatched when api key is missing', function () {
    config(['services.posthog.api_key' => null]);
    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => ['customer' => 'cus_test123', 'id' => 'sub_123']],
    ]));

    Bus::assertNotDispatched(TrackBilling::class);
});

// ========================================
// Plan mapping by Stripe price id
// ========================================

test('subscription created syncs the plan from the price id on first activation', function () {
    $this->account->update(['plan_id' => null]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    expect($this->account->fresh()->plan_id)->toBe($this->plan->id);
});

test('subscription updated maps the plan by its monthly price id', function () {
    $this->account->update(['plan_id' => null]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    expect($this->account->fresh()->plan_id)->toBe($this->plan->id);
});

test('subscription updated maps the plan by its yearly price id too', function () {
    $this->account->update(['plan_id' => null]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_yearly']]]],
        ]],
    ]));

    expect($this->account->fresh()->plan_id)->toBe($this->plan->id);
});

test('subscription updated leaves plan_id alone when the price already matches', function () {
    $this->account->update(['plan_id' => $this->plan->id]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    expect($this->account->fresh()->plan_id)->toBe($this->plan->id);
});

test('subscription updated ignores unknown price ids without erroring', function () {
    $this->account->update(['plan_id' => $this->plan->id]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'items' => ['data' => [['price' => ['id' => 'price_unknown_xyz']]]],
        ]],
    ]));

    expect($this->account->fresh()->plan_id)->toBe($this->plan->id);
});

test('subscription deleted clears the account plan_id', function () {
    $this->account->update(['plan_id' => $this->plan->id]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['customer' => 'cus_test123', 'status' => 'canceled']],
    ]));

    expect($this->account->fresh()->plan_id)->toBeNull();
});

test('subscription deleted is idempotent when plan_id is already null', function () {
    $this->account->update(['plan_id' => null]);

    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['customer' => 'cus_test123']],
    ]));

    expect($this->account->fresh()->plan_id)->toBeNull();

    // Re-delivery of a cancellation must not produce a duplicate
    // 'subscription.cancelled' event downstream.
    Bus::assertNotDispatched(TrackBilling::class);
});

// ========================================
// previousPlan propagation
// ========================================

test('subscription updated forwards the previous plan name to TrackBilling', function () {
    $this->account->update(['plan_id' => $this->plan->id]);

    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    Bus::assertDispatched(
        TrackBilling::class,
        fn ($job) => $job->event === BillingEvent::Updated && $job->previousPlan === $this->plan->name,
    );
});

test('subscription deleted forwards the previous plan name to TrackBilling', function () {
    $this->account->update(['plan_id' => $this->plan->id]);

    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['customer' => 'cus_test123']],
    ]));

    Bus::assertDispatched(
        TrackBilling::class,
        fn ($job) => $job->event === BillingEvent::Cancelled && $job->previousPlan === $this->plan->name,
    );
});

test('subscription created forwards a null previous plan when account had none', function () {
    $this->account->update(['plan_id' => null]);

    Bus::fake([TrackBilling::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    Bus::assertDispatched(
        TrackBilling::class,
        fn ($job) => $job->event === BillingEvent::Created && $job->previousPlan === null,
    );
});

// ========================================
// checkout.completed / trial.started tracking
// ========================================

test('subscription created dispatches TrackCheckoutCompleted when status is active', function () {
    Bus::fake([TrackCheckoutCompleted::class, TrackTrialStarted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'id' => 'sub_123',
            'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    Bus::assertDispatched(
        TrackCheckoutCompleted::class,
        fn ($job) => $job->accountId === (string) $this->account->id,
    );
    Bus::assertNotDispatched(TrackTrialStarted::class);
});

test('subscription created dispatches TrackTrialStarted when status is trialing', function () {
    Bus::fake([TrackCheckoutCompleted::class, TrackTrialStarted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'id' => 'sub_123',
            'status' => 'trialing',
            'trial_end' => now()->addDays(8)->timestamp,
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    Bus::assertDispatched(
        TrackTrialStarted::class,
        fn ($job) => $job->accountId === (string) $this->account->id,
    );
    Bus::assertNotDispatched(TrackCheckoutCompleted::class);
});

test('subscription created dispatches neither job for a status we do not track', function () {
    Bus::fake([TrackCheckoutCompleted::class, TrackTrialStarted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'id' => 'sub_123',
            'status' => 'incomplete',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    Bus::assertNotDispatched(TrackCheckoutCompleted::class);
    Bus::assertNotDispatched(TrackTrialStarted::class);
});

test('subscription updated and deleted do not dispatch TrackCheckoutCompleted or TrackTrialStarted', function (string $type) {
    Bus::fake([TrackCheckoutCompleted::class, TrackTrialStarted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => $type,
        'data' => ['object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'active']],
    ]));

    Bus::assertNotDispatched(TrackCheckoutCompleted::class);
    Bus::assertNotDispatched(TrackTrialStarted::class);
})->with([
    'updated' => 'customer.subscription.updated',
    'deleted' => 'customer.subscription.deleted',
]);

test('TrackCheckoutCompleted is not dispatched when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Bus::fake([TrackCheckoutCompleted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'id' => 'sub_123',
            'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    Bus::assertNotDispatched(TrackCheckoutCompleted::class);
});

test('TrackCheckoutCompleted and TrackTrialStarted are not dispatched when PostHog is disabled in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Bus::fake([TrackCheckoutCompleted::class, TrackTrialStarted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'id' => 'sub_123',
            'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    Bus::assertNotDispatched(TrackCheckoutCompleted::class);
    Bus::assertNotDispatched(TrackTrialStarted::class);
});

test('TrackCheckoutCompleted is dispatched in the local environment even when PostHog is disabled', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Bus::fake([TrackCheckoutCompleted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'customer' => 'cus_test123',
            'id' => 'sub_123',
            'status' => 'active',
            'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
        ]],
    ]));

    Bus::assertDispatched(TrackCheckoutCompleted::class);
});

// ========================================
// trial.converted tracking
// ========================================

test('subscription updated dispatches TrackTrialConverted when trialing transitions to active', function () {
    Bus::fake([TrackTrialConverted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'customer' => 'cus_test123',
                'id' => 'sub_123',
                'status' => 'active',
                'items' => ['data' => [['price' => ['id' => 'price_workspace_monthly']]]],
            ],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ]));

    Bus::assertDispatched(
        TrackTrialConverted::class,
        fn ($job) => $job->accountId === (string) $this->account->id,
    );
});

test('subscription updated dispatches TrackTrialConverted when a trial recovers from a failed first charge', function () {
    Bus::fake([TrackTrialConverted::class]);
    $trialEnd = now()->subDay()->timestamp;

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'customer' => 'cus_test123',
                'id' => 'sub_123',
                'status' => 'active',
                'trial_end' => $trialEnd,
                'items' => ['data' => [[
                    'price' => ['id' => 'price_workspace_monthly'],
                    'current_period_start' => $trialEnd,
                ]]],
            ],
            'previous_attributes' => ['status' => 'past_due'],
        ],
    ]));

    Bus::assertDispatched(
        TrackTrialConverted::class,
        fn ($job) => $job->accountId === (string) $this->account->id,
    );
});

test('subscription updated does not dispatch TrackTrialConverted for a past_due recovery on a subscription that never had a trial', function () {
    Bus::fake([TrackTrialConverted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'active'],
            'previous_attributes' => ['status' => 'past_due'],
        ],
    ]));

    Bus::assertNotDispatched(TrackTrialConverted::class);
});

test('subscription updated does not dispatch TrackTrialConverted for a later, unrelated past_due recovery on a long-converted subscription', function () {
    Bus::fake([TrackTrialConverted::class]);

    // trial_end is set (Stripe never clears it), but current_period_start is
    // months ahead of it — this is a routine card-decline-then-recovery on an
    // already-converted subscription, not the trial's own first charge retry.
    $trialEnd = now()->subMonths(6)->timestamp;
    $currentPeriodStart = now()->subDays(3)->timestamp;

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => [
                'customer' => 'cus_test123',
                'id' => 'sub_123',
                'status' => 'active',
                'trial_end' => $trialEnd,
                'items' => ['data' => [[
                    'price' => ['id' => 'price_workspace_monthly'],
                    'current_period_start' => $currentPeriodStart,
                ]]],
            ],
            'previous_attributes' => ['status' => 'past_due'],
        ],
    ]));

    Bus::assertNotDispatched(TrackTrialConverted::class);
});

test('subscription updated does not dispatch TrackTrialConverted when the new status is not active', function () {
    Bus::fake([TrackTrialConverted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'past_due'],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ]));

    Bus::assertNotDispatched(TrackTrialConverted::class);
});

test('TrackTrialConverted is not dispatched when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    Bus::fake([TrackTrialConverted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'active'],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ]));

    Bus::assertNotDispatched(TrackTrialConverted::class);
});

test('TrackTrialConverted is not dispatched when PostHog is disabled in production', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Bus::fake([TrackTrialConverted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'active'],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ]));

    Bus::assertNotDispatched(TrackTrialConverted::class);
});

test('TrackTrialConverted is dispatched in the local environment even when PostHog is disabled', function () {
    app()->detectEnvironment(fn () => 'local');
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);
    Bus::fake([TrackTrialConverted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'active'],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ]));

    Bus::assertDispatched(TrackTrialConverted::class);
});

// ========================================
// Idempotency (Stripe webhook redelivery)
// ========================================

test('redelivering the same stripe event id only processes it once', function () {
    Bus::fake([TrackTrialConverted::class]);

    $payload = [
        'id' => 'evt_test_redelivered',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'active'],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ];

    $this->listener->handle(new WebhookReceived($payload));
    $this->listener->handle(new WebhookReceived($payload));

    Bus::assertDispatchedTimes(TrackTrialConverted::class, 1);
});

test('two different stripe event ids are both processed', function () {
    Bus::fake([TrackTrialConverted::class]);

    $this->listener->handle(new WebhookReceived([
        'id' => 'evt_test_first',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'active'],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ]));
    $this->listener->handle(new WebhookReceived([
        'id' => 'evt_test_second',
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'active'],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ]));

    Bus::assertDispatchedTimes(TrackTrialConverted::class, 2);
});

test('an event without an id is still processed (no idempotency key available)', function () {
    Bus::fake([TrackTrialConverted::class]);

    $this->listener->handle(new WebhookReceived([
        'type' => 'customer.subscription.updated',
        'data' => [
            'object' => ['customer' => 'cus_test123', 'id' => 'sub_123', 'status' => 'active'],
            'previous_attributes' => ['status' => 'trialing'],
        ],
    ]));

    Bus::assertDispatchedTimes(TrackTrialConverted::class, 1);
});
