<?php

declare(strict_types=1);

use App\Actions\Media\DeleteOrphanedMediaFiles;
use App\Actions\User\DeleteOrRestoreStrandedMember;
use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('no-ops when the user still has a membership on the leaving account', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;
    $member->update(['account_id' => $owner->account_id]);

    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    DeleteOrRestoreStrandedMember::execute($member->fresh(), $owner->account);

    expect(User::find($member->id))->not->toBeNull();
    expect($member->fresh()->account_id)->toBe($owner->account_id);
    expect(Account::find($personalAccountId))->not->toBeNull();
});

test('no-ops for the account owner', function () {
    $owner = User::factory()->create();

    DeleteOrRestoreStrandedMember::execute($owner->fresh(), $owner->account);

    expect(User::find($owner->id))->not->toBeNull();
    expect(Account::find($owner->account_id))->not->toBeNull();
});

test('deletes a stranded invitee and their empty personal account', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;
    $member->update(['account_id' => $owner->account_id]);

    DeleteOrRestoreStrandedMember::execute($member->fresh(), $owner->account);

    expect(User::find($member->id))->toBeNull();
    expect(Account::find($personalAccountId))->toBeNull();
});

test('restores a personal account that still has a workspace', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;

    $personalWorkspace = Workspace::factory()->create([
        'account_id' => $personalAccountId,
        'user_id' => $member->id,
    ]);
    $personalWorkspace->members()->attach($member->id, ['role' => Role::Admin->value]);

    $member->update([
        'account_id' => $owner->account_id,
        'current_workspace_id' => null,
    ]);

    DeleteOrRestoreStrandedMember::execute($member->fresh(), $owner->account);

    $member->refresh();

    expect($member->account_id)->toBe($personalAccountId);
    expect($member->current_workspace_id)->toBe($personalWorkspace->id);
    expect($member->isAccountOwner())->toBeTrue();
});

test('deletes stranded invitee and revokes their passport tokens', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $member->update(['account_id' => $owner->account_id]);

    $token = $member->createToken('API Key')->token;
    expect($token->revoked)->toBeFalse();

    DeleteOrRestoreStrandedMember::execute($member->fresh(), $owner->account);

    expect(User::find($member->id))->toBeNull();
    expect(AccessToken::find($token->id)->revoked)->toBeTrue();
});

test('deletes stranded invitee avatar media files from storage', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $member = User::factory()->create();
    $member->update(['account_id' => $owner->account_id]);

    $avatar = $member->addMedia(
        UploadedFile::fake()->image('avatar.jpg', 200, 200),
        'avatar',
    );
    $avatarPath = $avatar->path;
    Storage::assertExists($avatarPath);

    $mediaPaths = DeleteOrRestoreStrandedMember::execute($member->fresh(), $owner->account);
    DeleteOrphanedMediaFiles::execute($mediaPaths);

    expect(User::find($member->id))->toBeNull();
    expect(Media::find($avatar->id))->toBeNull();
    Storage::assertMissing($avatarPath);
});

test('forAccountMembers only processes users without remaining account workspaces when flagged', function () {
    $owner = User::factory()->create();
    $stranded = User::factory()->create();
    $stillMember = User::factory()->create();
    $strandedPersonalId = $stranded->account_id;

    $stranded->update(['account_id' => $owner->account_id]);
    $stillMember->update(['account_id' => $owner->account_id]);

    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($stillMember->id, ['role' => Role::Member->value]);

    DeleteOrRestoreStrandedMember::forAccountMembers(
        $owner->account,
        $owner->id,
        onlyWithoutAccountWorkspaces: true,
    );

    expect(User::find($stranded->id))->toBeNull();
    expect(Account::find($strandedPersonalId))->toBeNull();
    expect(User::find($stillMember->id))->not->toBeNull();
    expect($stillMember->fresh()->account_id)->toBe($owner->account_id);
});
