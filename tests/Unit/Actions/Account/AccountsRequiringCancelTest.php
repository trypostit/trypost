<?php

declare(strict_types=1);

use App\Actions\Account\AccountsRequiringCancel;

test('owner delete preflight includes member personals before the shared account', function () {
    [
        'owner' => $owner,
        'personal_account_id' => $personalAccountId,
    ] = strandedMemberOnSharedAccount(withPersonalWorkspace: true);

    $ids = AccountsRequiringCancel::forDeletingUser($owner, $owner->account, true)
        ->pluck('id')
        ->all();

    expect($ids)->toContain($personalAccountId)
        ->and($ids)->toContain($owner->account_id)
        ->and(end($ids))->toBe($owner->account_id)
        ->and(array_search($personalAccountId, $ids, true))
        ->toBeLessThan(array_search($owner->account_id, $ids, true));
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
