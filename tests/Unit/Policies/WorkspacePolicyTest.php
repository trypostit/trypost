<?php

declare(strict_types=1);

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
    $workspace->members()->attach($user->id, membershipPivot('member'));

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
    $workspace->members()->attach($member->id, membershipPivot('member'));

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
    $workspace->members()->attach($admin->id, membershipPivot('admin'));

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
    $workspace->members()->attach($member->id, membershipPivot('member'));

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
    $workspace->members()->attach($admin->id, membershipPivot('admin'));
    $workspace->members()->attach($member->id, membershipPivot('member'));

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
    $workspace->members()->attach($admin->id, membershipPivot('admin'));

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
    $workspace->members()->attach($admin->id, membershipPivot('admin'));

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
    $workspace->members()->attach($admin->id, membershipPivot('admin'));
    $workspace->members()->attach($regularUser->id, membershipPivot('member'));

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
    $workspace->members()->attach($admin->id, membershipPivot('admin'));
    $workspace->members()->attach($member->id, membershipPivot('member'));

    expect($this->policy->manageAccounts($owner, $workspace))->toBeTrue();
    expect($this->policy->manageAccounts($admin, $workspace))->toBeTrue();
    expect($this->policy->manageAccounts($member, $workspace))->toBeFalse();
});

test('account owner and workspace admin can manage webhooks', function () {
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
    $workspace->members()->attach($admin->id, membershipPivot('admin'));
    $workspace->members()->attach($member->id, membershipPivot('member'));

    expect($this->policy->manageWebhooks($owner, $workspace))->toBeTrue();
    expect($this->policy->manageWebhooks($admin, $workspace))->toBeTrue();
    expect($this->policy->manageWebhooks($member, $workspace))->toBeFalse();
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
    $workspace->members()->attach($member->id, membershipPivot('member'));

    expect($this->policy->createPost($owner, $workspace))->toBeTrue();
    expect($this->policy->createPost($member, $workspace))->toBeTrue();
    expect($this->policy->createPost($otherUser, $workspace))->toBeFalse();
});

test('a member who needs approval can write posts but cannot publish directly, approve or manage', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $requester = workspaceMember($workspace, 'approval');

    expect($this->policy->view($requester, $workspace))->toBeTrue()
        ->and($this->policy->createPost($requester, $workspace))->toBeTrue()
        ->and($this->policy->publishDirectly($requester, $workspace))->toBeFalse()
        ->and($this->policy->approvePosts($requester, $workspace))->toBeFalse()
        ->and($this->policy->manageRepurposes($requester, $workspace))->toBeFalse()
        ->and($this->policy->manageWebhooks($requester, $workspace))->toBeFalse()
        ->and($this->policy->manageTeam($requester, $workspace))->toBeFalse()
        ->and($this->policy->inviteMember($requester, $workspace))->toBeFalse();
});

test('owners, admins and direct publishers can publish directly and approve', function (string $access) {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $actor = $access === 'owner' ? $owner : workspaceMember($workspace, $access);

    expect($this->policy->publishDirectly($actor, $workspace))->toBeTrue()
        ->and($this->policy->approvePosts($actor, $workspace))->toBeTrue()
        ->and($this->policy->manageRepurposes($actor, $workspace))->toBeTrue();
})->with(['owner', 'admin', 'member']);

test('a user outside the workspace cannot write, publish or approve', function () {
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['account_id' => $owner->account_id, 'user_id' => $owner->id]);
    $outsider = workspaceOutsider($workspace);

    expect($this->policy->createPost($outsider, $workspace))->toBeFalse()
        ->and($this->policy->publishDirectly($outsider, $workspace))->toBeFalse()
        ->and($this->policy->approvePosts($outsider, $workspace))->toBeFalse();
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
