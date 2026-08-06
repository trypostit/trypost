<?php

declare(strict_types=1);

use App\Actions\Invite\RemoveMember;
use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
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
    $result = $member->createToken('Removed Workspace');
    $token = AccessToken::query()->findOrFail($result->token->id);
    $token->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    RemoveMember::execute($workspace, $member->id);

    $member->refresh();

    expect($workspace->members()->where('user_id', $member->id)->exists())->toBeFalse();
    expect($member->current_workspace_id)->toBe($other->id);
    expect($member->account_id)->toBe($owner->account_id);
    expect($token->fresh()->revoked)->toBeTrue();
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

test('remove member keeps mcp oauth when create-post access remains elsewhere', function () {
    [
        'member' => $member,
        'shared_workspaces' => [$sharedA, $sharedB],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 2,
        setMemberCurrent: true,
    );
    $oauth = mcpAccessToken($member, mcpOauthClient());

    RemoveMember::execute($sharedA, $member->id);

    expect($oauth->fresh()->revoked)->toBeFalse()
        ->and($member->fresh()->can('createPost', $sharedB))->toBeTrue();
});

test('remove member revokes mcp oauth when create-post access is lost', function () {
    [
        'member' => $member,
        'shared_workspaces' => [$sharedA, $sharedB],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 2,
        setMemberCurrent: true,
    );
    $sharedB->members()->updateExistingPivot($member->id, [
        'role' => Role::Viewer->value,
    ]);
    $oauth = mcpAccessToken($member, mcpOauthClient());

    RemoveMember::execute($sharedA, $member->id);

    expect($oauth->fresh()->revoked)->toBeTrue()
        ->and($member->fresh()->can('createPost', $sharedB))->toBeFalse();
});
