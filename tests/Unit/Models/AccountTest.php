<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Account;
use App\Models\Offer;
use App\Models\Plan;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    $this->seed(PlanSeeder::class);
    Carbon::setTestNow('2026-05-14 12:00:00');
});

test('isPastDue returns false without a subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create(['trial_ends_at' => null]);

    expect($account->isPastDue())->toBeFalse();
});

test('subscriptionStatus returns none without a subscription', function () {
    $account = Account::factory()->create(['trial_ends_at' => null]);

    expect($account->subscriptionStatus())->toBe('none');
});

test('subscriptionStatus returns the current Stripe status', function () {
    $account = Account::factory()->create(['trial_ends_at' => null]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_123',
    ]);

    expect($account->subscriptionStatus())->toBe('past_due');
});

test('subscriptionStatus returns canceled during the grace period', function () {
    $account = Account::factory()->create(['trial_ends_at' => null]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
        'ends_at' => now()->addDay(),
    ]);

    expect($account->subscriptionStatus())->toBe('canceled');
});

test('subscriptionStatus returns canceled after the subscription has ended', function () {
    $account = Account::factory()->create(['trial_ends_at' => null]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
        'ends_at' => now(),
    ]);

    expect($account->subscriptionStatus())->toBe('canceled');
});

test('isPastDue returns true for a past_due subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_123',
    ]);

    expect($account->isPastDue())->toBeTrue();
});

test('isPastDue returns false for an active subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    expect($account->isPastDue())->toBeFalse();
});

test('isPastDue returns false when self-hosted', function () {
    config(['trypost.self_hosted' => true]);

    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_123',
    ]);

    expect($account->isPastDue())->toBeFalse();
});

test('isOnTrial includes a generic trial without a subscription', function () {
    $account = Account::factory()->create(['trial_ends_at' => now()->addDays(7)]);

    expect($account->isOnTrial())->toBeTrue();
});

test('isOnTrial returns false for account without trial or subscription', function () {
    $account = Account::factory()->create(['trial_ends_at' => null]);

    expect($account->isOnTrial())->toBeFalse();
});

test('isOnTrial returns true via subscription trial when account has no generic trial', function () {
    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_123',
        'trial_ends_at' => now()->addDays(5),
    ]);

    expect($account->isOnTrial())->toBeTrue();
});

test('activeTrialEndsAt returns null when not on any trial', function () {
    $account = Account::factory()->create(['trial_ends_at' => null]);

    expect($account->activeTrialEndsAt())->toBeNull();
});

test('activeTrialEndsAt returns generic trial date when only generic is active', function () {
    $endsAt = now()->addDays(7);
    $account = Account::factory()->create(['trial_ends_at' => $endsAt]);

    expect($account->activeTrialEndsAt()?->toDateTimeString())->toBe($endsAt->toDateTimeString());
});

test('activeTrialEndsAt returns subscription date when only subscription trial is active', function () {
    $subscriptionEndsAt = now()->addDays(5);
    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_123',
        'trial_ends_at' => $subscriptionEndsAt,
    ]);

    expect($account->activeTrialEndsAt()?->toDateTimeString())
        ->toBe($subscriptionEndsAt->toDateTimeString());
});

test('activeTrialEndsAt returns subscription date when both generic and subscription trials are present', function () {
    $genericEndsAt = now()->addDays(7);
    $subscriptionEndsAt = now()->addDays(14);

    $account = Account::factory()->create([
        'trial_ends_at' => $genericEndsAt,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_123',
        'trial_ends_at' => $subscriptionEndsAt,
    ]);

    expect($account->activeTrialEndsAt()?->toDateTimeString())
        ->toBe($subscriptionEndsAt->toDateTimeString());
});

test('activeTrialEndsAt returns null for paying customer post-trial', function () {
    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
        'plan_id' => Plan::where('slug', Slug::Workspace)->value('id'),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
        'trial_ends_at' => null,
    ]);

    expect($account->activeTrialEndsAt())->toBeNull();
    expect($account->isOnTrial())->toBeFalse();
});

test('hasAppAccess is always true when self-hosted', function () {
    config(['trypost.self_hosted' => true]);

    $account = Account::factory()->create(['trial_ends_at' => null]);

    expect($account->hasAppAccess())->toBeTrue();
});

test('hasAppAccess is false without a subscription on saas', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create(['trial_ends_at' => null]);

    expect($account->hasAppAccess())->toBeFalse();
});

test('hasAppAccess is true for an active subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    expect($account->fresh()->hasAppAccess())->toBeTrue();
});

test('hasAppAccess is true for a trialing subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_123',
        'trial_ends_at' => now()->addDays(5),
    ]);

    expect($account->fresh()->hasAppAccess())->toBeTrue();
});

test('hasAppAccess is true for a past_due subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create([
        'trial_ends_at' => null,
        'stripe_id' => 'cus_test_'.fake()->uuid(),
    ]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_123',
    ]);

    expect($account->fresh()->hasAppAccess())->toBeTrue();
});

test('hasAppAccess lasts as long as a generic trial', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create(['trial_ends_at' => now()->addDays(7)]);

    expect($account->hasAppAccess())->toBeTrue();

    Carbon::setTestNow(now()->addDays(8));

    expect($account->fresh()->hasAppAccess())->toBeFalse()
        ->and($account->fresh()->isOnTrial())->toBeFalse();
});

test('a subscription created during a redeemed trial without a card was subscribed during that trial', function () {
    $account = Account::factory()->create();
    $account->offers()->attach(Offer::factory()->trial(7)->withoutCard()->create(), ['claimed_at' => now()->subDays(3), 'redeemed_at' => now()->subDays(3)]);

    expect($account->subscribedDuringTrialWithoutCard(now()))->toBeTrue()
        ->and($account->subscribedDuringTrialWithoutCard(now()->addDays(5)))->toBeFalse();
});

test('a trial that asked for a card is not a trial without a card', function () {
    $account = Account::factory()->create();
    $account->offers()->attach(Offer::factory()->trial(7)->create(), ['claimed_at' => now()->subDay(), 'redeemed_at' => now()->subDay()]);

    expect($account->subscribedDuringTrialWithoutCard(now()))->toBeFalse();
});

test('a claimed trial without a card that was never redeemed is not a trial', function () {
    $account = Account::factory()->create();
    $account->offers()->attach(Offer::factory()->trial(7)->withoutCard()->create(), ['claimed_at' => now()->subDay()]);

    expect($account->subscribedDuringTrialWithoutCard(now()))->toBeFalse();
});
