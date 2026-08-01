<?php

declare(strict_types=1);

use App\Actions\Account\AccountsRequiringCancel;

test('owner delete preflight only includes the shared account', function () {
    [
        'owner' => $owner,
        'personal_account_id' => $personalAccountId,
    ] = strandedMemberOnSharedAccount();

    $ids = AccountsRequiringCancel::forDeletingUser($owner, $owner->account, true)
        ->pluck('id')
        ->all();

    expect($ids)->toBe([$owner->account_id])
        ->and($ids)->not->toContain($personalAccountId);
});

test('member delete preflight only includes accounts they own', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'personal_account_id' => $personalAccountId,
    ] = strandedMemberOnSharedAccount();

    $accounts = AccountsRequiringCancel::forDeletingUser($member, $owner->account, false);

    expect($accounts->pluck('id')->all())->toBe([$personalAccountId]);
});
