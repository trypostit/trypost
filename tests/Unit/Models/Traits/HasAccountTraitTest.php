<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

it('returns the loaded account without querying again', function () {
    $user = User::factory()->create();
    $user->load('account');

    expect($user->accountOrFail())->toBeInstanceOf(Account::class)
        ->and($user->accountOrFail()->is($user->account))->toBeTrue();
});

it('throws ModelNotFoundException when the user has no account', function () {
    $user = User::factory()->create();
    $user->forceFill(['account_id' => null])->saveQuietly();
    $user->unsetRelation('account');

    $user->accountOrFail();
})->throws(ModelNotFoundException::class);

it('identifies the account owner', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['account_id' => $owner->account_id]);

    expect($owner->isAccountOwner())->toBeTrue()
        ->and($member->isAccountOwner())->toBeFalse();
});

it('checks whether the user belongs to an account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    expect($user->belongsToAccount($user->account_id))->toBeTrue()
        ->and($user->belongsToAccount($other->account_id))->toBeFalse();
});
