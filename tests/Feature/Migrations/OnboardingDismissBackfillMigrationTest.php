<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => true,
    ]);

    $this->runBackfill = function (): void {
        $migration = require database_path(
            'migrations/2026_07_29_183500_backfill_onboarding_dismissed_for_accounts_with_app_access.php',
        );

        $migration->up();
    };
});

test('dismisses accounts with past_due subscriptions that still have app access', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $user = User::factory()->create();
    $account = $user->account;

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_123',
    ]);

    expect($account->fresh()->hasAppAccess())->toBeTrue()
        ->and($account->fresh()->onboarding_dismissed_at)->toBeNull();

    ($this->runBackfill)();

    expect($account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();
});

test('dismisses accounts on a canceled subscription that is still in grace', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $user = User::factory()->create();
    $account = $user->account;

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_123',
        'ends_at' => now()->addDays(5),
    ]);

    expect($account->fresh()->hasAppAccess())->toBeTrue();

    ($this->runBackfill)();

    expect($account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();
});

test('does not dismiss accounts without app access', function () {
    $user = User::factory()->create();

    ($this->runBackfill)();

    expect($user->account->fresh()->onboarding_dismissed_at)->toBeNull();
});

test('does not dismiss accounts with subscription statuses that lack app access', function (string $status) {
    $user = User::factory()->create();
    $account = $user->account;

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => $status,
        'stripe_price' => 'price_123',
    ]);

    expect($account->fresh()->hasAppAccess())->toBeFalse("Status {$status} unexpectedly has app access.");

    ($this->runBackfill)();

    expect($account->fresh()->onboarding_dismissed_at)->toBeNull();
})->with([
    'unpaid',
    'incomplete',
    'incomplete_expired',
]);

test('does not dismiss an account whose latest subscription is incomplete', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $user = User::factory()->create();
    $account = $user->account;
    subscribeAccount($account);

    Carbon::setTestNow(now()->addMinute());
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'incomplete',
        'stripe_price' => 'price_123',
    ]);

    expect($account->fresh()->hasAppAccess())->toBeFalse();

    ($this->runBackfill)();

    expect($account->fresh()->onboarding_dismissed_at)->toBeNull();
});

test('does not overwrite already completed or dismissed accounts', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $completed = User::factory()->create();
    subscribeAccount($completed->account);
    $completed->account->forceFill(['onboarding_completed_at' => now()->subDay()])->save();

    $dismissed = User::factory()->create();
    subscribeAccount($dismissed->account);
    $dismissed->account->forceFill(['onboarding_dismissed_at' => now()->subDay()])->save();

    ($this->runBackfill)();

    expect($completed->account->fresh()->onboarding_dismissed_at)->toBeNull()
        ->and($completed->account->fresh()->onboarding_completed_at?->equalTo(now()->subDay()))->toBeTrue()
        ->and($dismissed->account->fresh()->onboarding_dismissed_at?->equalTo(now()->subDay()))->toBeTrue();
});

test('dismisses generic-trial accounts when card is not required', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['trypost.billing.require_card_for_trial' => false]);

    $user = User::factory()->create();
    DB::table('accounts')->where('id', $user->account_id)->update([
        'trial_ends_at' => now()->addDays(7),
    ]);

    expect($user->account->fresh()->hasAppAccess())->toBeTrue();

    ($this->runBackfill)();

    expect($user->account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();
});

test('dismisses every account in self hosted mode regardless of subscription', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['trypost.self_hosted' => true]);

    $withoutSubscription = User::factory()->create();
    $withSubscription = User::factory()->create();
    subscribeAccount($withSubscription->account);

    $alreadyCompleted = User::factory()->create();
    $alreadyCompleted->account->forceFill(['onboarding_completed_at' => now()->subDay()])->save();

    ($this->runBackfill)();

    expect($withoutSubscription->account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue()
        ->and($withSubscription->account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue()
        ->and($alreadyCompleted->account->fresh()->onboarding_dismissed_at)->toBeNull();
});

test('stamps every matching account through the chunked update', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $accounts = collect();
    for ($i = 0; $i < 501; $i++) {
        $user = User::factory()->create();
        subscribeAccount($user->account);
        $accounts->push($user->account);
    }

    ($this->runBackfill)();

    foreach ($accounts as $account) {
        expect($account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();
    }
});
