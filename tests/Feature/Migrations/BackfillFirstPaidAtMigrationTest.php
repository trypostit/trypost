<?php

declare(strict_types=1);

use App\Models\Account;

beforeEach(function () {
    $this->runBackfill = function (): void {
        $migration = require database_path('migrations/2026_10_20_155336_backfill_first_paid_at_on_accounts.php');

        $migration->up();
    };

    $this->subscribe = function (Account $account, string $status, array $attributes = []): void {
        $account->subscriptions()->create([
            'type' => Account::SUBSCRIPTION_NAME,
            'stripe_id' => 'sub_'.fake()->uuid(),
            'stripe_status' => $status,
            'stripe_price' => 'price_123',
            ...$attributes,
        ]);
    };
});

test('marks accounts whose subscription went past checkout and trial', function (string $status, array $attributes) {
    $account = Account::factory()->create();
    ($this->subscribe)($account, $status, $attributes);

    ($this->runBackfill)();

    expect($account->fresh()->first_paid_at)->not->toBeNull();
})->with([
    'active' => ['active', []],
    'past due' => ['past_due', []],
    'past due on a later renewal' => ['past_due', ['trial_ends_at' => now()->subMonths(3)]],
    'cancelled after its trial' => ['canceled', ['trial_ends_at' => now()->subMonth(), 'ends_at' => now()->subDay()]],
]);

test('leaves accounts that were never charged', function (string $status, array $attributes) {
    $account = Account::factory()->create();
    ($this->subscribe)($account, $status, $attributes);

    ($this->runBackfill)();

    expect($account->fresh()->first_paid_at)->toBeNull();
})->with([
    'trialing' => ['trialing', ['trial_ends_at' => now()->addDays(3)]],
    'incomplete' => ['incomplete', []],
    'incomplete expired' => ['incomplete_expired', []],
    'past due on the first charge after its trial' => ['past_due', ['trial_ends_at' => now()->subDays(3)]],
    'unpaid after the first charge after its trial' => ['unpaid', ['trial_ends_at' => now()->subDays(10)]],
    'cancelled inside its trial' => ['canceled', ['trial_ends_at' => now()->addDays(3), 'ends_at' => now()->subDay()]],
]);

test('keeps a first payment already recorded', function () {
    $this->freezeTime();
    $account = Account::factory()->create();
    $account->forceFill(['first_paid_at' => now()->subYear()->startOfSecond()])->save();
    ($this->subscribe)($account, 'active');

    ($this->runBackfill)();

    expect($account->fresh()->first_paid_at->equalTo(now()->subYear()->startOfSecond()))->toBeTrue();
});
