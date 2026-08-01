<?php

declare(strict_types=1);

use App\Actions\User\SettleStrandedMember;
use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('no-ops when the user still has a membership on the leaving account', function () {
    ['owner' => $owner, 'member' => $member, 'personal_account_id' => $personalAccountId] = strandedMemberOnSharedAccount();

    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    SettleStrandedMember::execute($member->fresh(), $owner->account);

    expect(User::find($member->id))->not->toBeNull();
    expect($member->fresh()->account_id)->toBe($owner->account_id);
    expect(Account::find($personalAccountId))->not->toBeNull();
});

test('no-ops for the account owner', function () {
    $owner = User::factory()->create();

    SettleStrandedMember::execute($owner->fresh(), $owner->account);

    expect(User::find($owner->id))->not->toBeNull();
    expect(Account::find($owner->account_id))->not->toBeNull();
});

test('deletes a stranded invitee and flushes empty personal account after settle', function () {
    ['owner' => $owner, 'member' => $member, 'personal_account_id' => $personalAccountId] = strandedMemberOnSharedAccount();

    $settled = SettleStrandedMember::execute($member->fresh(), $owner->account);

    expect(User::find($member->id))->toBeNull();
    expect($settled->emptyAccountIds)->toContain($personalAccountId);
    expect(Account::find($personalAccountId))->not->toBeNull();

    $settled->flush();

    expect(Account::find($personalAccountId))->toBeNull();
});

test('deletes a stranded invitee who still owns a personal workspace', function () {
    ['owner' => $owner, 'member' => $member, 'personal_account_id' => $personalAccountId, 'personal_workspace' => $personalWorkspace] = strandedMemberOnSharedAccount(withPersonalWorkspace: true);

    $settled = SettleStrandedMember::execute($member->fresh(), $owner->account);
    $settled->flush();

    expect(User::find($member->id))->toBeNull();
    expect(Account::find($personalAccountId))->toBeNull();
    expect(Workspace::find($personalWorkspace->id))->toBeNull();
});

test('deletes stranded invitee and revokes their passport tokens', function () {
    ['owner' => $owner, 'member' => $member] = strandedMemberOnSharedAccount();

    $token = $member->createToken('API Key')->token;
    expect($token->revoked)->toBeFalse();

    SettleStrandedMember::execute($member->fresh(), $owner->account);

    expect(User::find($member->id))->toBeNull();
    expect(AccessToken::find($token->id)->revoked)->toBeTrue();
});

test('deletes stranded invitee avatar media files from storage', function () {
    Storage::fake();

    ['owner' => $owner, 'member' => $member] = strandedMemberOnSharedAccount();

    $avatar = $member->addMedia(
        UploadedFile::fake()->image('avatar.jpg', 200, 200),
        'avatar',
    );
    $avatarPath = $avatar->path;
    Storage::assertExists($avatarPath);

    SettleStrandedMember::execute($member->fresh(), $owner->account)->flush();

    expect(User::find($member->id))->toBeNull();
    expect(Media::find($avatar->id))->toBeNull();
    Storage::assertMissing($avatarPath);
});

test('strandedWithoutMemberships only processes users without remaining account workspaces', function () {
    [
        'owner' => $owner,
        'member' => $stranded,
        'personal_account_id' => $strandedPersonalId,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        attachMember: false,
    );

    [
        'member' => $stillMember,
    ] = strandedMemberOnSharedAccount(
        owner: $owner,
        sharedWorkspaces: 0,
    );
    $workspace->members()->attach($stillMember->id, ['role' => Role::Member->value]);

    SettleStrandedMember::strandedWithoutMemberships(
        $owner->account,
        exceptUserId: $owner->id,
    )->flush();

    expect(User::find($stranded->id))->toBeNull();
    expect(Account::find($strandedPersonalId))->toBeNull();
    expect(User::find($stillMember->id))->not->toBeNull();
    expect($stillMember->fresh()->account_id)->toBe($owner->account_id);
});
