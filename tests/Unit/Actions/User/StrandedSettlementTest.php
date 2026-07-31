<?php

declare(strict_types=1);

use App\Actions\User\StrandedSettlement;
use App\Models\Account;
use App\Models\User;

test('flush deletes queued empty accounts', function () {
    $user = User::factory()->create();
    $emptyAccountId = $user->account_id;
    $user->delete();

    (new StrandedSettlement(emptyAccountIds: [$emptyAccountId]))->flush();

    expect(Account::find($emptyAccountId))->toBeNull();
});

test('merge combines media paths and unique empty account ids', function () {
    $merged = (new StrandedSettlement(
        mediaPaths: ['a.jpg'],
        emptyAccountIds: ['1', '2'],
    ))->merge(new StrandedSettlement(
        mediaPaths: ['b.jpg'],
        emptyAccountIds: ['2', '3'],
    ));

    expect($merged->mediaPaths)->toBe(['a.jpg', 'b.jpg']);
    expect($merged->emptyAccountIds)->toBe(['1', '2', '3']);
});
