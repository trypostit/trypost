<?php

declare(strict_types=1);

use App\Actions\Account\DeleteAccount;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

test('delete account force-deletes members including those with a personal workspace', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'personal_account_id' => $personalAccountId,
        'personal_workspace' => $personalWorkspace,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        withPersonalWorkspace: true,
        sharedWorkspaces: 1,
        setMemberCurrent: true,
    );

    $accountId = $owner->account_id;

    $settlement = DB::transaction(fn () => DeleteAccount::execute($owner->account, $owner));
    $settlement->flush();

    expect(Account::find($accountId))->toBeNull();
    expect(Workspace::find($workspace->id))->toBeNull();
    expect(User::find($member->id))->toBeNull();
    expect(Account::find($personalAccountId))->toBeNull();
    expect(Workspace::find($personalWorkspace->id))->toBeNull();
    expect($settlement->mediaPaths)->toBeArray();
});

test('delete account removes pending invites', function () {
    [
        'owner' => $owner,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(sharedWorkspaces: 1);

    $invite = Invite::factory()->create([
        'account_id' => $owner->account_id,
        'invited_by' => $owner->id,
        'workspaces' => [$workspace->id],
    ]);

    DB::transaction(fn () => DeleteAccount::execute($owner->account, $owner))->flush();

    expect(Invite::find($invite->id))->toBeNull();
});

test('delete account no-ops when the account row is already gone', function () {
    $owner = User::factory()->create();
    $account = $owner->account;
    $accountId = $account->id;
    $account->delete();

    $settlement = DB::transaction(fn () => DeleteAccount::execute($account, $owner));

    expect($settlement->mediaPaths)->toBe([]);
    expect($settlement->emptyAccountIds)->toBe([]);
    expect(Account::find($accountId))->toBeNull();
});
