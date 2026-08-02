<?php

declare(strict_types=1);

use App\Actions\Invite\RemoveMember;
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
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        setMemberCurrent: true,
    );

    RemoveMember::execute($workspace, $member->id);

    expect($workspace->members()->where('user_id', $member->id)->exists())->toBeFalse();
    expect(User::find($member->id))->toBeNull();
});

test('remove member prefers another workspace on the same account', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'shared_workspaces' => [$sharedA, $sharedB],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 2,
        setMemberCurrent: true,
    );

    RemoveMember::execute($sharedA, $member->id);

    $member->refresh();

    expect($member->account_id)->toBe($owner->account_id);
    expect($member->current_workspace_id)->toBe($sharedB->id);
});
