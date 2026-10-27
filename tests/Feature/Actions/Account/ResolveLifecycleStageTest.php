<?php

declare(strict_types=1);

use App\Actions\Account\ResolveLifecycleStage;
use App\Enums\Account\LifecycleStage;
use App\Models\Account;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
});

test('each stage is resolved from access, channels and the last published post', function (?string $subscriptionStatus, bool $hasConnectedChannel, ?int $daysSinceLastPublish, LifecycleStage $expected) {
    $this->freezeTime();

    $account = Account::factory()->create(['trial_ends_at' => null, 'first_paid_at' => $subscriptionStatus === 'canceled' ? now()->subMonth() : null]);

    if (filled($subscriptionStatus)) {
        $account->subscriptions()->create([
            'type' => Account::SUBSCRIPTION_NAME,
            'stripe_id' => 'sub_'.fake()->uuid(),
            'stripe_status' => $subscriptionStatus,
            'stripe_price' => 'price_123',
            'ends_at' => $subscriptionStatus === 'canceled' ? now()->subDay() : null,
        ]);
    }

    $lastPublishedAt = filled($daysSinceLastPublish) ? now()->subDays($daysSinceLastPublish) : null;

    expect(ResolveLifecycleStage::execute($account->load('subscriptions'), $lastPublishedAt, $hasConnectedChannel))->toBe($expected);
})->with([
    'churned after a real subscription' => ['canceled', true, 1, LifecycleStage::Churned],
    'signed up without a subscription' => [null, true, 1, LifecycleStage::SignedUp],
    'signed up after an incomplete checkout' => ['incomplete', false, null, LifecycleStage::SignedUp],
    'signed up after an expired incomplete checkout' => ['incomplete_expired', false, null, LifecycleStage::SignedUp],
    'onboarding without a connected channel' => ['active', false, null, LifecycleStage::Onboarding],
    'connected without a published post' => ['active', true, null, LifecycleStage::Connected],
    'active when the last post went out 7 days ago' => ['active', true, ResolveLifecycleStage::ACTIVE_WITHIN_DAYS, LifecycleStage::Active],
    'at risk when the last post went out 8 days ago' => ['active', true, ResolveLifecycleStage::ACTIVE_WITHIN_DAYS + 1, LifecycleStage::AtRisk],
    'at risk when the last post went out 21 days ago' => ['trialing', true, ResolveLifecycleStage::AT_RISK_WITHIN_DAYS, LifecycleStage::AtRisk],
    'dormant when the last post went out 22 days ago' => ['active', true, ResolveLifecycleStage::AT_RISK_WITHIN_DAYS + 1, LifecycleStage::Dormant],
]);

test('a card trial cancelled before its first charge is signed up, not churned', function () {
    $this->freezeTime();

    $account = Account::factory()->create(['trial_ends_at' => null, 'first_paid_at' => null]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_trial_cancelled',
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_123',
        'trial_ends_at' => null,
        'ends_at' => now()->subDays(4),
    ]);

    expect(ResolveLifecycleStage::execute($account->load('subscriptions'), null, true))->toBe(LifecycleStage::SignedUp)
        ->and($account->hasHadBillableSubscription())->toBeTrue();
});

test('a paying account keeps its stage while past due or cancelled on its grace period', function (string $status, bool $onGracePeriod) {
    $this->freezeTime();

    $account = Account::factory()->create(['trial_ends_at' => null]);
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => $status,
        'stripe_price' => 'price_123',
        'ends_at' => $onGracePeriod ? now()->addDay() : null,
    ]);

    expect(ResolveLifecycleStage::execute($account->load('subscriptions'), now()->subDay(), true))->toBe(LifecycleStage::Active);
})->with([
    'past due' => ['past_due', false],
    'cancelled on its grace period' => ['active', true],
]);
