<?php

declare(strict_types=1);

use App\Actions\User\EnsurePersonalAccount;
use App\Models\Account;
use App\Models\User;

test('returns the current account when the user already owns it', function () {
    $user = User::factory()->create();

    $account = EnsurePersonalAccount::execute($user);

    expect($account->id)->toBe($user->account_id);
    expect($account->owner_id)->toBe($user->id);
    expect(Account::where('owner_id', $user->id)->count())->toBe(1);
});

test('reclaims an orphaned personal account when the user joined another account', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;

    $member->update(['account_id' => $owner->account_id]);

    $account = EnsurePersonalAccount::execute($member->fresh());

    expect($account->id)->toBe($personalAccountId);
    expect($member->fresh()->account_id)->toBe($personalAccountId);
    expect($account->owner_id)->toBe($member->id);
});

test('creates a personal account when the user has none of their own', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['account_id' => $owner->account_id]);
    Account::where('owner_id', $member->id)->delete();
    $member->update(['account_id' => $owner->account_id]);

    $account = EnsurePersonalAccount::execute($member->fresh());

    expect($member->fresh()->account_id)->toBe($account->id);
    expect($account->owner_id)->toBe($member->id);
    expect($account->billing_email)->toBe($member->email);
});
