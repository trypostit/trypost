<?php

declare(strict_types=1);

use App\Actions\Invite\RemoveMember;
use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

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

test('remove member keeps mcp oauth when the member remains a viewer elsewhere', function () {
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

    expect($oauth->fresh()->revoked)->toBeFalse()
        ->and($member->fresh()->can('createPost', $sharedB))->toBeFalse()
        ->and($member->fresh()->can('view', $sharedB))->toBeTrue();
});

test('remove member revokes api keys without touching mcp oauth grants on the same workspace id', function () {
    [
        'member' => $member,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        setMemberCurrent: true,
    );

    $pat = $member->createToken('API Key');
    $patToken = AccessToken::query()->findOrFail($pat->token->id);
    $patToken->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    // Mis-bound workspace_id must still not classify this as a PAT revoke target.
    $oauth = mcpAccessToken($member, mcpOauthClient());
    $oauth->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    // Keep the member alive (second workspace) so we only exercise API-key revoke.
    $other = Workspace::factory()->create([
        'account_id' => $member->account_id,
        'user_id' => $member->account->owner_id,
    ]);
    $other->members()->attach($member->id, ['role' => Role::Member->value]);

    RemoveMember::execute($workspace, $member->id);

    expect($patToken->fresh()->revoked)->toBeTrue()
        ->and($oauth->fresh()->revoked)->toBeFalse();
});
