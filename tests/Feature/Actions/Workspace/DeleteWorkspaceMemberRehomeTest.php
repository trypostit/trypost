<?php

declare(strict_types=1);

use App\Actions\Workspace\DeleteWorkspace;
use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

test('delete workspace rehomes stranded members to a personal account', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;
    $member->update(['account_id' => $owner->account_id]);

    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);

    $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $workspace->id]);

    DeleteWorkspace::execute($workspace);

    $member->refresh();

    expect($member->account_id)->toBe($personalAccountId);
    expect($member->current_workspace_id)->toBeNull();
    expect($member->isAccountOwner())->toBeTrue();
});

test('delete workspace does not rehome members who still have another workspace on the account', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $member->update(['account_id' => $owner->account_id]);

    $first = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $second = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);

    foreach ([$first, $second] as $workspace) {
        $workspace->members()->attach($owner->id, ['role' => Role::Admin->value]);
        $workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    }

    $member->update(['current_workspace_id' => $first->id]);
    $sharedAccountId = $owner->account_id;

    DeleteWorkspace::execute($first);

    $member->refresh();

    expect($member->account_id)->toBe($sharedAccountId);
    expect($member->current_workspace_id)->toBe($second->id);
});

test('delete workspace rehomes members even when they still have a personal workspace membership', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;

    $personalWorkspace = Workspace::factory()->create([
        'account_id' => $personalAccountId,
        'user_id' => $member->id,
    ]);
    $personalWorkspace->members()->attach($member->id, ['role' => Role::Admin->value]);

    $sharedWorkspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);

    $sharedWorkspace->members()->attach($owner->id, ['role' => Role::Admin->value]);
    $sharedWorkspace->members()->attach($member->id, ['role' => Role::Member->value]);

    $member->update([
        'account_id' => $owner->account_id,
        'current_workspace_id' => $sharedWorkspace->id,
    ]);

    DeleteWorkspace::execute($sharedWorkspace);

    $member->refresh();

    expect($member->account_id)->toBe($personalAccountId);
    expect($member->current_workspace_id)->toBe($personalWorkspace->id);
    expect($member->isAccountOwner())->toBeTrue();
});
