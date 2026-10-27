<?php

declare(strict_types=1);

use App\Models\Account;

test('clears trial dates left on accounts so they grant no access', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create(['trial_ends_at' => now()->addDays(10)]);
    $untouched = Account::factory()->create(['trial_ends_at' => null]);

    expect($account->hasAppAccess())->toBeTrue();

    (require database_path('migrations/2026_10_27_195326_clear_account_trials_before_offers.php'))->up();

    expect($account->fresh()->trial_ends_at)->toBeNull()
        ->and($account->fresh()->hasAppAccess())->toBeFalse()
        ->and($untouched->fresh()->trial_ends_at)->toBeNull();
});
