<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\Repurpose;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->owner = User::factory()->create(['account_id' => $this->account->id]);
    $this->account->update(['owner_id' => $this->owner->id]);

    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);

    $this->member = User::factory()->create(['account_id' => $this->account->id, 'current_workspace_id' => $this->workspace->id]);
    $this->viewer = User::factory()->create(['account_id' => $this->account->id, 'current_workspace_id' => $this->workspace->id]);

    $this->workspace->members()->attach($this->member->id, ['role' => Role::Member->value]);
    $this->workspace->members()->attach($this->viewer->id, ['role' => Role::Viewer->value]);

    $this->repurpose = Repurpose::factory()->create(['workspace_id' => $this->workspace->id]);
});

test('an owner and a member can manage repurposes', function () {
    foreach ([$this->owner, $this->member] as $user) {
        expect($user->can('viewAny', Repurpose::class))->toBeTrue()
            ->and($user->can('create', Repurpose::class))->toBeTrue()
            ->and($user->can('update', $this->repurpose))->toBeTrue()
            ->and($user->can('delete', $this->repurpose))->toBeTrue();
    }
});

test('a viewer cannot manage repurposes', function () {
    expect($this->viewer->can('create', Repurpose::class))->toBeFalse()
        ->and($this->viewer->can('update', $this->repurpose))->toBeFalse()
        ->and($this->viewer->can('delete', $this->repurpose))->toBeFalse();
});

test('a repurpose from another workspace is invisible', function () {
    $stranger = User::factory()->create();
    $strangerWorkspace = Workspace::factory()->create(['user_id' => $stranger->id]);
    $stranger->update(['current_workspace_id' => $strangerWorkspace->id]);

    expect($stranger->can('view', $this->repurpose))->toBeFalse()
        ->and($stranger->can('update', $this->repurpose))->toBeFalse();
});
