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
