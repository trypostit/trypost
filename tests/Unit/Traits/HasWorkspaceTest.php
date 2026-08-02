<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('user can get workspaces they belong to', function () {
    $user = User::factory()->create();
    $workspace1 = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace2 = Workspace::factory()->create(['user_id' => $user->id]);

    // Add user as owner to both workspaces via pivot
    $workspace1->members()->attach($user->id, ['role' => Role::Member->value]);
    $workspace2->members()->attach($user->id, ['role' => Role::Member->value]);

    expect($user->workspaces)->toHaveCount(2);
    expect($user->workspaces->pluck('id')->toArray())->toContain($workspace1->id, $workspace2->id);
});

test('user can get workspaces as member', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    expect($member->workspaces)->toHaveCount(1);
    expect($member->workspaces->first()->id)->toBe($workspace->id);
});

test('user can get current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);

    expect($user->currentWorkspace->id)->toBe($workspace->id);
});

test('user can switch workspace', function () {
    $user = User::factory()->create();
    $workspace1 = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace2 = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace1->id]);

    $user->switchWorkspace($workspace2);

    expect($user->fresh()->current_workspace_id)->toBe($workspace2->id);
});

test('user belongs to member workspace on the same account', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $member->update(['account_id' => $owner->account_id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    expect($member->belongsToWorkspace($workspace))->toBeTrue();
});

test('user does not belong to a workspace on another account even with a pivot', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    expect($member->account_id)->not->toBe($workspace->account_id);
    expect($member->belongsToWorkspace($workspace))->toBeFalse();
});

test('user belongs to owned workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);

    expect($user->belongsToWorkspace($workspace))->toBeTrue();
});

test('accountWorkspaces excludes memberships on other accounts', function () {
    $sharedOwner = User::factory()->create();
    $user = User::factory()->create();
    $personalAccountId = $user->account_id;

    $personalWorkspace = Workspace::factory()->create([
        'account_id' => $personalAccountId,
        'user_id' => $user->id,
    ]);
    $personalWorkspace->members()->attach($user->id, ['role' => Role::Admin->value]);

    $sharedWorkspace = Workspace::factory()->create([
        'account_id' => $sharedOwner->account_id,
        'user_id' => $sharedOwner->id,
    ]);
    $sharedWorkspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['account_id' => $sharedOwner->account_id]);

    expect($user->fresh()->accountWorkspaces()->pluck('workspaces.id')->all())
        ->toEqualCanonicalizing([$sharedWorkspace->id]);
});

test('user does not belong to other workspace', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $otherUser->id]);

    expect($user->belongsToWorkspace($workspace))->toBeFalse();
});

test('user can get owned workspaces count', function () {
    $user = User::factory()->create();
    $workspaces = Workspace::factory()->count(3)->create(['user_id' => $user->id]);

    foreach ($workspaces as $workspace) {
        $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    }

    expect($user->ownedWorkspacesCount())->toBe(3);
});
