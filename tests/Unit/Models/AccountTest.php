<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Account;
use App\Models\Plan;
use Carbon\Carbon;
use Database\Seeders\PlanSeeder;

beforeEach(function () {
    config(['trypost.billing.require_card_for_trial' => true]);
    $this->seed(PlanSeeder::class);
    Carbon::setTestNow('2026-05-14 12:00:00');
});

test('isPastDue returns false without a subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create(['trial_ends_at' => null]);

    expect($account->isPastDue())->toBeFalse();
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

test('isOnTrial ignores generic trial when there is no subscription', function () {
    $account = Account::factory()->create(['trial_ends_at' => now()->addDays(7)]);

    expect($account->isOnTrial())->toBeFalse();
});

test('isOnTrial includes generic trial when card is not required', function () {
    config(['trypost.billing.require_card_for_trial' => false]);

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

    expect($account->activeTrialEndsAt())->toBeNull();
});

test('activeTrialEndsAt returns generic trial date when card is not required', function () {
    config(['trypost.billing.require_card_for_trial' => false]);

    $endsAt = now()->addDays(7);
    $account = Account::factory()->create(['trial_ends_at' => $endsAt]);

    expect($account->activeTrialEndsAt()?->toDateTimeString())
        ->toBe($endsAt->toDateTimeString());
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

test('hasAppAccess ignores generic trial when card is required', function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => true,
    ]);

    $account = Account::factory()->create(['trial_ends_at' => now()->addDays(7)]);

    expect($account->hasAppAccess())->toBeFalse()
        ->and($account->isOnTrial())->toBeFalse();
});

test('hasAppAccess allows generic trial when card is not required', function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => false,
    ]);

    $account = Account::factory()->create(['trial_ends_at' => now()->addDays(7)]);

    expect($account->hasAppAccess())->toBeTrue()
        ->and($account->isOnTrial())->toBeTrue()
        ->and($account->subscribed(Account::SUBSCRIPTION_NAME))->toBeFalse();
});

test('hasAppAccess denies expired generic trial when card is not required', function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => false,
    ]);

    $account = Account::factory()->create(['trial_ends_at' => now()->subDay()]);

    expect($account->hasAppAccess())->toBeFalse()
        ->and($account->isOnTrial())->toBeFalse();
});
