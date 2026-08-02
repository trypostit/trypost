<?php

declare(strict_types=1);

use App\Actions\Workspace\DeleteWorkspace;
use App\Enums\UserWorkspace\Role;
use App\Models\Invite;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('delete workspace deletes stranded members', function () {
    [
        'member' => $member,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 2,
        attachMemberToAll: false,
        setMemberCurrent: true,
    );

    DeleteWorkspace::execute($workspace);

    expect(User::find($member->id))->toBeNull();
});

test('delete workspace does not delete members who still have another workspace on the account', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'shared_workspaces' => [$first, $second],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 2,
        setMemberCurrent: true,
    );

    DeleteWorkspace::execute($first);

    $member->refresh();

    expect($member->account_id)->toBe($owner->account_id);
    expect($member->current_workspace_id)->toBe($second->id);
});

test('delete workspace falls back to an account workspace the owner is not pivoted into', function () {
    $owner = User::factory()->create();

    $current = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $current->members()->attach($owner->id, ['role' => Role::Admin->value]);
    $owner->update(['current_workspace_id' => $current->id]);

    $memberCreated = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    // Owner can access via account ownership but is not on the pivot.

    expect(DeleteWorkspace::execute($current))->toBeTrue();

    $owner->refresh();

    expect($owner->current_workspace_id)->toBe($memberCreated->id);
    expect($owner->belongsToWorkspace($memberCreated))->toBeTrue();
});

test('delete workspace removes pending invites that only target that workspace', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);

    $invite = Invite::factory()->create([
        'account_id' => $owner->account_id,
        'invited_by' => $owner->id,
        'workspaces' => [$workspace->id],
    ]);

    expect(DeleteWorkspace::execute($workspace))->toBeTrue();

    expect(Invite::find($invite->id))->toBeNull();
});

test('delete workspace prunes the deleted workspace id from multi-workspace invites', function () {
    $owner = User::factory()->create();
    $first = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $second = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $first->members()->attach($owner->id, ['role' => Role::Admin->value]);

    $invite = Invite::factory()->create([
        'account_id' => $owner->account_id,
        'invited_by' => $owner->id,
        'workspaces' => [$first->id, $second->id],
    ]);

    expect(DeleteWorkspace::execute($first))->toBeTrue();

    expect($invite->fresh()->workspaces)->toBe([$second->id]);
});

test('delete workspace deletes workspace media files and rows', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);

    $media = $workspace->addMedia(
        UploadedFile::fake()->image('logo.jpg'),
        'logo',
    );
    $mediaPath = $media->path;
    Storage::assertExists($mediaPath);

    expect(DeleteWorkspace::execute($workspace))->toBeTrue();

    expect(Media::find($media->id))->toBeNull();
    expect(Workspace::find($workspace->id))->toBeNull();
    Storage::assertMissing($mediaPath);
});

test('delete workspace returns false when saas blocks the last workspace', function () {
    config(['trypost.self_hosted' => false]);

    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);

    expect(DeleteWorkspace::execute($workspace))->toBeFalse();
    expect(Workspace::find($workspace->id))->not->toBeNull();
});
