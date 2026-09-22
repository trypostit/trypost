<?php

declare(strict_types=1);

use App\Jobs\PostHog\HydrateSubscriptionAnalytics;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);
});

test('command syncs every account and hydrates only active default subscriptions', function () {
    $activeAccount = Account::factory()->create();
    $activeSubscription = $activeAccount->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_one',
        'stripe_status' => 'active',
        'stripe_price' => 'price_socials_monthly',
    ]);
    $canceledAccount = Account::factory()->create();
    $canceledAccount->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_two',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_workspaces_monthly',
    ]);
    $unsubscribedAccount = Account::factory()->create();
    Bus::fake([HydrateSubscriptionAnalytics::class, SyncAccountUsage::class]);

    $this->artisan('posthog:backfill-subscription-analytics')
        ->expectsOutputToContain('Queued subscription analytics sync for 3 accounts; 1 active subscriptions require Stripe hydration.')
        ->assertSuccessful();

    Bus::assertDispatchedTimes(SyncAccountUsage::class, 3);
    Bus::assertDispatchedTimes(HydrateSubscriptionAnalytics::class, 1);
    Bus::assertDispatched(
        HydrateSubscriptionAnalytics::class,
        fn (HydrateSubscriptionAnalytics $job): bool => $job->subscriptionId === (string) $activeSubscription->id,
    );

    foreach ([$activeAccount, $canceledAccount, $unsubscribedAccount] as $account) {
        Bus::assertDispatched(
            SyncAccountUsage::class,
            fn (SyncAccountUsage $job): bool => $job->accountId === (string) $account->id,
        );
    }
});

test('command skips hydration when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false]);
    $account = Account::factory()->create();
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_disabled',
        'stripe_status' => 'active',
        'stripe_price' => 'price_socials_monthly',
    ]);
    Bus::fake([HydrateSubscriptionAnalytics::class, SyncAccountUsage::class]);

    $this->artisan('posthog:backfill-subscription-analytics')
        ->expectsOutputToContain('PostHog is disabled; no accounts were queued.')
        ->assertSuccessful();

    Bus::assertNotDispatched(HydrateSubscriptionAnalytics::class);
    Bus::assertNotDispatched(SyncAccountUsage::class);
});
