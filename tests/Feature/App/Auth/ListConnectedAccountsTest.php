<?php

declare(strict_types=1);

use App\Actions\Auth\ListConnectedAccounts;
use App\Models\User;

test('lists every provider with whether the user is linked to it', function () {
    $user = User::factory()->create(['github_id' => 'gh-1', 'google_id' => null]);

    expect(ListConnectedAccounts::execute($user))->toBe([
        ['provider' => 'google', 'label' => 'Google', 'connected' => false, 'can_disconnect' => false],
        ['provider' => 'github', 'label' => 'GitHub', 'connected' => true, 'can_disconnect' => true],
    ]);
});

test('the only sign-in method of a user without a password cannot be disconnected', function () {
    $user = User::factory()->create(['password' => null, 'github_id' => 'gh-1', 'google_id' => null]);

    expect(collect(ListConnectedAccounts::execute($user))->firstWhere('provider', 'github'))
        ->toMatchArray(['connected' => true, 'can_disconnect' => false]);
});
