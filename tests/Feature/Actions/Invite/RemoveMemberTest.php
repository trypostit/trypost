<?php

declare(strict_types=1);

use App\Actions\Invite\RemoveMember;
use App\Models\Account;
use App\Models\User;

test('remove member clears current workspace when it was the removed membership', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'shared_workspaces' => [$workspace, $other],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 2,
        setMemberCurrent: true,
    );

    RemoveMember::execute($workspace, $member->id);

    $member->refresh();

    expect($workspace->members()->where('user_id', $member->id)->exists())->toBeFalse();
    expect($member->current_workspace_id)->toBe($other->id);
    expect($member->account_id)->toBe($owner->account_id);
});

test('remove member deletes a user who loses their last account workspace', function () {
    [
        'member' => $member,
        'personal_account_id' => $personalAccountId,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        setMemberCurrent: true,
    );

    RemoveMember::execute($workspace, $member->id);

    expect($workspace->members()->where('user_id', $member->id)->exists())->toBeFalse();
    expect(User::find($member->id))->toBeNull();
    expect(Account::find($personalAccountId))->toBeNull();
});

test('remove member prefers a same-account workspace over a personal membership', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'shared_workspaces' => [$sharedA, $sharedB],
    ] = strandedMemberOnSharedAccount(
        withPersonalWorkspace: true,
        sharedWorkspaces: 2,
        setMemberCurrent: true,
    );

    RemoveMember::execute($sharedA, $member->id);

    $member->refresh();

    expect($member->account_id)->toBe($owner->account_id);
    expect($member->current_workspace_id)->toBe($sharedB->id);
});

test('remove member restores personal workspace instead of keeping a cross-account current', function () {
    [
        'member' => $member,
        'personal_account_id' => $personalAccountId,
        'personal_workspace' => $personalWorkspace,
        'shared_workspaces' => [$shared],
    ] = strandedMemberOnSharedAccount(
        withPersonalWorkspace: true,
        sharedWorkspaces: 1,
        setMemberCurrent: true,
    );

    RemoveMember::execute($shared, $member->id);

    $member->refresh();

    expect($member->account_id)->toBe($personalAccountId);
    expect($member->isAccountOwner())->toBeTrue();
    expect($member->current_workspace_id)->toBe($personalWorkspace->id);
});
