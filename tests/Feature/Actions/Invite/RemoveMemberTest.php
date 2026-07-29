<?php

declare(strict_types=1);

use App\Actions\Invite\RemoveMember;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('remove member clears current workspace when it was the removed membership', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['account_id' => $owner->account_id]);

    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $other = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);

    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $other->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $workspace->id]);

    RemoveMember::execute($workspace, $member->id);

    $member->refresh();

    expect($workspace->members()->where('user_id', $member->id)->exists())->toBeFalse();
    expect($member->current_workspace_id)->toBe($other->id);
    expect($member->account_id)->toBe($owner->account_id);
});

test('remove member rehomes a user who loses their last account workspace', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;
    $member->update(['account_id' => $owner->account_id]);

    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $workspace->id]);

    RemoveMember::execute($workspace, $member->id);

    $member->refresh();

    expect($workspace->members()->where('user_id', $member->id)->exists())->toBeFalse();
    expect($member->account_id)->toBe($personalAccountId);
    expect($member->isAccountOwner())->toBeTrue();
    expect($member->current_workspace_id)->toBeNull();
});

test('remove member prefers a same-account workspace over a personal membership', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;

    $personalWorkspace = Workspace::factory()->create([
        'account_id' => $personalAccountId,
        'user_id' => $member->id,
    ]);
    $personalWorkspace->members()->attach($member->id, ['role' => Role::Admin->value]);

    $member->update(['account_id' => $owner->account_id]);

    $sharedA = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $sharedB = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $sharedA->members()->attach($member->id, ['role' => Role::Member->value]);
    $sharedB->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $sharedA->id]);

    RemoveMember::execute($sharedA, $member->id);

    $member->refresh();

    expect($member->account_id)->toBe($owner->account_id);
    expect($member->current_workspace_id)->toBe($sharedB->id);
});

test('remove member rehomes to personal workspace instead of keeping a cross-account current', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;

    $personalWorkspace = Workspace::factory()->create([
        'account_id' => $personalAccountId,
        'user_id' => $member->id,
    ]);
    $personalWorkspace->members()->attach($member->id, ['role' => Role::Admin->value]);

    $member->update(['account_id' => $owner->account_id]);

    $shared = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $shared->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $shared->id]);

    RemoveMember::execute($shared, $member->id);

    $member->refresh();

    expect($member->account_id)->toBe($personalAccountId);
    expect($member->isAccountOwner())->toBeTrue();
    expect($member->current_workspace_id)->toBe($personalWorkspace->id);
});
