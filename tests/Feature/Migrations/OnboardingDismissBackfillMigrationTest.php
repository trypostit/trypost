<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->runBackfill = function (): void {
        $migration = require database_path(
            'migrations/2026_07_29_183500_backfill_onboarding_dismissed_for_accounts_with_app_access.php',
        );

        $migration->up();
    };
});

test('completes every open account regardless of subscription or hosting mode', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $open = User::factory()->create();
    $subscribed = User::factory()->create();
    subscribeAccount($subscribed->account);

    config(['trypost.self_hosted' => true]);
    $selfHosted = User::factory()->create();

    expect($open->account->subscriptions()->exists())->toBeFalse()
        ->and($selfHosted->account->subscriptions()->exists())->toBeFalse();

    ($this->runBackfill)();

    expect($open->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue()
        ->and($subscribed->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue()
        ->and($selfHosted->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('does not overwrite already completed or dismissed accounts', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $completed = User::factory()->create();
    $completed->account->forceFill(['onboarding_completed_at' => now()->subDay()])->save();

    $dismissed = User::factory()->create();
    $dismissed->account->forceFill(['onboarding_dismissed_at' => now()->subDay()])->save();

    ($this->runBackfill)();

    expect($completed->account->fresh()->onboarding_completed_at?->equalTo(now()->subDay()))->toBeTrue()
        ->and($completed->account->fresh()->onboarding_dismissed_at)->toBeNull()
        ->and($dismissed->account->fresh()->onboarding_dismissed_at?->equalTo(now()->subDay()))->toBeTrue()
        ->and($dismissed->account->fresh()->onboarding_completed_at)->toBeNull();
});

test('stamps every open account in a single update', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $accounts = collect();
    for ($i = 0; $i < 50; $i++) {
        $accounts->push(User::factory()->create()->account);
    }

    ($this->runBackfill)();

    foreach ($accounts as $account) {
        expect($account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
    }
});

test('down clears completed_at stamped by the backfill', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $open = User::factory()->create();
    $dismissed = User::factory()->create();
    $dismissed->account->forceFill(['onboarding_dismissed_at' => now()->subDay()])->save();

    ($this->runBackfill)();

    $migration = require database_path(
        'migrations/2026_07_29_183500_backfill_onboarding_dismissed_for_accounts_with_app_access.php',
    );
    $migration->down();

    expect($open->account->fresh()->onboarding_completed_at)->toBeNull()
        ->and($dismissed->account->fresh()->onboarding_dismissed_at?->equalTo(now()->subDay()))->toBeTrue()
        ->and($dismissed->account->fresh()->onboarding_completed_at)->toBeNull();
});
