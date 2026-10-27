<?php

declare(strict_types=1);

use App\Actions\User\CreateUser;

test('the default trial length is 8 days', function () {
    expect(config('cashier.trial_days'))->toBe(8);
});

test('signup grants a trial of cashier.trial_days in no-card mode', function () {
    config(['trypost.billing.require_card_for_trial' => false]);

    $user = CreateUser::execute([
        'name' => 'Trial User',
        'email' => 'trial@example.com',
        'password' => 'secret123',
    ]);

    expect($user->account->trial_ends_at->toDateString())
        ->toBe(now()->addDays(config('cashier.trial_days'))->toDateString());
});
