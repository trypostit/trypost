<?php

declare(strict_types=1);

use App\Jobs\PostHog\SyncAccountUsage;
use App\Listeners\SyncSubscriptionAnalytics;
use App\Models\Account;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use App\Support\Billing\SubscriptionAnalytics;
use Illuminate\Support\Facades\Bus;
use Laravel\Cashier\Events\WebhookHandled;

beforeEach(function () {
    $this->account = Account::factory()->create(['stripe_id' => 'cus_test123']);
    $this->subscription = $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_socials_monthly',
    ]);
    $this->listener = new SyncSubscriptionAnalytics(new SubscriptionAnalytics);
});

test('subscription created stores first month offer data and queues account sync', function () {
    Bus::fake([SyncAccountUsage::class]);
    $startedAt = now()->startOfSecond();
    $endsAt = $startedAt->copy()->addMonth();

    $this->listener->handle(new WebhookHandled([
        'id' => 'evt_subscription_created',
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_test123',
            'customer' => 'cus_test123',
            'start_date' => $startedAt->timestamp,
            'metadata' => [
                ConfigureSubscriptionCheckout::FIRST_MONTH_COUPON_METADATA_KEY => 'SOCIALS_18USD',
            ],
            'items' => ['data' => [[
                'current_period_start' => $startedAt->timestamp,
                'current_period_end' => $endsAt->timestamp,
            ]]],
        ]],
    ]));

    $subscription = $this->subscription->fresh();

    expect($subscription->stripe_started_at?->toDateTimeString())->toBe($startedAt->toDateTimeString())
        ->and($subscription->first_month_coupon_id)->toBe('SOCIALS_18USD')
        ->and($subscription->first_month_offer_ends_at?->toDateTimeString())->toBe($endsAt->toDateTimeString());

    Bus::assertDispatched(
        SyncAccountUsage::class,
        fn (SyncAccountUsage $job): bool => $job->accountId === (string) $this->account->id,
    );
});

test('later subscription updates do not move the original offer end date', function () {
    $originalEnd = now()->subDay()->startOfSecond();
    $this->subscription->update([
        'stripe_started_at' => $originalEnd->copy()->subMonth(),
        'first_month_coupon_id' => 'SOCIALS_18USD',
        'first_month_offer_ends_at' => $originalEnd,
    ]);
    Bus::fake([SyncAccountUsage::class]);

    $this->listener->handle(new WebhookHandled([
        'type' => 'customer.subscription.updated',
        'data' => ['object' => [
            'id' => 'sub_test123',
            'customer' => 'cus_test123',
            'start_date' => $originalEnd->copy()->subMonth()->timestamp,
            'metadata' => [
                ConfigureSubscriptionCheckout::FIRST_MONTH_COUPON_METADATA_KEY => 'SOCIALS_18USD',
            ],
            'items' => ['data' => [[
                'current_period_start' => $originalEnd->timestamp,
                'current_period_end' => $originalEnd->copy()->addMonth()->timestamp,
            ]]],
        ]],
    ]));

    expect($this->subscription->fresh()->first_month_offer_ends_at?->toDateTimeString())
        ->toBe($originalEnd->toDateTimeString());

    Bus::assertDispatched(SyncAccountUsage::class);
});

test('subscription deleted queues current account state without rewriting offer data', function () {
    $offerEnd = now()->addWeek()->startOfSecond();
    $this->subscription->update([
        'first_month_coupon_id' => 'SOCIALS_18USD',
        'first_month_offer_ends_at' => $offerEnd,
    ]);
    Bus::fake([SyncAccountUsage::class]);

    $this->listener->handle(new WebhookHandled([
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => [
            'id' => 'sub_test123',
            'customer' => 'cus_test123',
        ]],
    ]));

    expect($this->subscription->fresh()->first_month_offer_ends_at?->toDateTimeString())
        ->toBe($offerEnd->toDateTimeString());

    Bus::assertDispatched(SyncAccountUsage::class);
});

test('unrelated Stripe events are ignored', function () {
    Bus::fake([SyncAccountUsage::class]);

    $this->listener->handle(new WebhookHandled([
        'type' => 'invoice.payment_succeeded',
        'data' => ['object' => ['customer' => 'cus_test123']],
    ]));

    Bus::assertNotDispatched(SyncAccountUsage::class);
});
