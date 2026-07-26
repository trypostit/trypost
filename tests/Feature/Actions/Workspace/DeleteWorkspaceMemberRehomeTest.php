<?php

declare(strict_types=1);

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

    DeleteWorkspace::execute($owner, $workspace);

    $member->refresh();

    expect($member->account_id)->toBe($personalAccountId);
    expect($member->current_workspace_id)->toBeNull();
    expect($member->isAccountOwner())->toBeTrue();
});

test('delete workspace does not rehome members who still have another workspace', function () {
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

    DeleteWorkspace::execute($owner, $first);

    $member->refresh();

    expect($member->account_id)->toBe($sharedAccountId);
    expect($member->current_workspace_id)->toBe($second->id);
});
