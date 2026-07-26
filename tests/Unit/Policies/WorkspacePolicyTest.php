<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;

beforeEach(function () {
    $this->policy = new WorkspacePolicy;
});

test('any user can view any workspaces', function () {
    $user = User::factory()->create();

    expect($this->policy->viewAny($user))->toBeTrue();
});

test('owner can view workspace', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $user->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);

    expect($this->policy->view($user, $workspace))->toBeTrue();
});

test('member can view workspace', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $member = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    expect($this->policy->view($member, $workspace))->toBeTrue();
});

test('non member cannot view workspace', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $otherUser = User::factory()->create(); // different account
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);

    expect($this->policy->view($otherUser, $workspace))->toBeFalse();
});

test('account owner can create workspace', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    expect($this->policy->create($user))->toBeTrue();
});

test('non owner cannot create workspace', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $owner->id]);
    $member = User::factory()->create(['account_id' => $account->id]);

    expect($this->policy->create($member))->toBeFalse();
});

test('account owner can update workspace', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $user->id]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);

    expect($this->policy->update($user, $workspace))->toBeTrue();
});

test('workspace admin can update workspace', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $admin = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);

    expect($this->policy->update($admin, $workspace))->toBeTrue();
});

test('regular member cannot update workspace', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $member = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    expect($this->policy->update($member, $workspace))->toBeFalse();
});

test('account owner can delete workspace but workspace admin cannot', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $admin = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $member = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    expect($this->policy->delete($owner, $workspace))->toBeTrue();
    expect($this->policy->delete($admin, $workspace))->toBeFalse();
    expect($this->policy->delete($member, $workspace))->toBeFalse();
});

test('account owner can restore workspace but workspace admin cannot', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $admin = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);

    expect($this->policy->restore($owner, $workspace))->toBeTrue();
    expect($this->policy->restore($admin, $workspace))->toBeFalse();
});

test('account owner can force delete workspace but workspace admin cannot', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $admin = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);

    expect($this->policy->forceDelete($owner, $workspace))->toBeTrue();
    expect($this->policy->forceDelete($admin, $workspace))->toBeFalse();
});

test('account owner and admin can manage team', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $admin = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $regularUser = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);
    $workspace->members()->attach($regularUser->id, ['role' => Role::Member->value]);

    expect($this->policy->manageTeam($owner, $workspace))->toBeTrue();
    expect($this->policy->manageTeam($admin, $workspace))->toBeTrue();
    expect($this->policy->manageTeam($regularUser, $workspace))->toBeFalse();
});

test('account owner and workspace admin can manage accounts', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $admin = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $member = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    expect($this->policy->manageAccounts($owner, $workspace))->toBeTrue();
    expect($this->policy->manageAccounts($admin, $workspace))->toBeTrue();
    expect($this->policy->manageAccounts($member, $workspace))->toBeFalse();
});

test('account owner and workspace member can create post', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $member = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $otherUser = User::factory()->create(); // different account
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    expect($this->policy->createPost($owner, $workspace))->toBeTrue();
    expect($this->policy->createPost($member, $workspace))->toBeTrue();
    expect($this->policy->createPost($otherUser, $workspace))->toBeFalse();
});

test('a viewer can view but cannot create posts or manage the team', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $viewer = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);

    expect($this->policy->view($viewer, $workspace))->toBeTrue();
    expect($this->policy->createPost($viewer, $workspace))->toBeFalse();
    expect($this->policy->manageTeam($viewer, $workspace))->toBeFalse();
    expect($this->policy->inviteMember($viewer, $workspace))->toBeFalse();
});

test('only account owner can manage billing', function () {
    $account = Account::factory()->create();
    $owner = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $account->update(['owner_id' => $owner->id]);
    $admin = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $member = User::factory()->create([
        'account_id' => $account->id,
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $owner->id,
    ]);

    expect($this->policy->manageBilling($owner, $workspace))->toBeTrue();
    expect($this->policy->manageBilling($admin, $workspace))->toBeFalse();
    expect($this->policy->manageBilling($member, $workspace))->toBeFalse();
});
