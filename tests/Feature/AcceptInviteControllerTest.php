<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->account = Account::factory()->create();
    $this->owner = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->owner->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->owner->id,
    ]);
});

test('show invite displays invite details for guest when not self_hosted', function () {
    config()->set('trypost.self_hosted', false);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'newuser@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->get(route('app.invites.show', $invite));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/AcceptInvite', false)
        ->where('expired', false)
        ->has('invite')
        ->where('invite.id', $invite->id)
        ->where('invite.email', 'newuser@example.com')
        ->where('invite.account.name', $this->account->name)
    );
});

test('show invite displays invite details for guest when self_hosted (page renders, gate happens on /register)', function () {
    config()->set('trypost.self_hosted', true);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'newuser@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->get(route('app.invites.show', $invite));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/AcceptInvite', false)
        ->where('expired', false)
        ->has('invite')
        ->where('invite.id', $invite->id)
    );
});

test('show invite displays invite details for authenticated user', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->actingAs($user)->get(route('app.invites.show', $invite));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/AcceptInvite', false)
        ->where('expired', false)
        ->has('invite')
    );
});

test('show invite marks expired when workspace is gone without mutating on GET', function () {
    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'newuser@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $this->workspace->delete();

    $response = $this->get(route('app.invites.show', $invite));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('auth/AcceptInvite', false)
        ->where('expired', true)
        ->where('invite', null)
    );

    // Prefetch-safe: cleanup is deferred to accept/decline / workspace delete.
    expect(Invite::find($invite->id))->not->toBeNull();
});

test('show invite returns 404 for non-existent invite', function () {
    $response = $this->get(route('app.invites.show', 'non-existent-uuid'));

    $response->assertNotFound();
});

test('accept invite requires authentication', function () {
    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
    ]);

    $response = $this->post(route('app.invites.accept', $invite));

    $response->assertRedirect(route('login'));
});

test('accept invite adds user to account and workspaces', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);
    $personalAccountId = $user->account_id;

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->actingAs($user)->post(route('app.invites.accept', $invite));

    $response->assertRedirect(route('app.calendar'));

    // User should be added to the account
    $user->refresh();
    expect($user->account_id)->toBe($this->account->id);
    expect(Account::find($personalAccountId))->toBeNull();

    // User should be member of workspace
    expect($this->workspace->members()->where('user_id', $user->id)->exists())->toBeTrue();

    // User's current workspace should be updated
    expect($user->current_workspace_id)->toBe($this->workspace->id);

    // Invite should be marked as accepted
    $invite->refresh();
    expect($invite->accepted_at)->not->toBeNull();
});

test('accept invite assigns the exact role from the invite', function (Role $role) {
    $user = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
        'role' => $role,
    ]);

    $this->actingAs($user)
        ->post(route('app.invites.accept', $invite))
        ->assertRedirect(route('app.calendar'));

    $member = $this->workspace->members()->where('user_id', $user->id)->first();

    expect($member)->not->toBeNull();
    expect($member->pivot->role)->toBe($role->value);
})->with([
    'viewer' => Role::Viewer,
    'admin' => Role::Admin,
    'member' => Role::Member,
]);

test('accept invite fails for wrong email', function () {
    $user = User::factory()->create([
        'email' => 'different@example.com',
    ]);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->actingAs($user)->post(route('app.invites.accept', $invite));

    $response->assertRedirect(route('app.workspaces.create'));
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    // Invite should NOT be accepted
    $invite->refresh();
    expect($invite->accepted_at)->toBeNull();
});

test('accept invite handles already member of account', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.com',
        'account_id' => $this->account->id,
    ]);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
        'role' => Role::Member,
    ]);

    $response = $this->actingAs($user)->post(route('app.invites.accept', $invite));

    $response->assertRedirect(route('app.calendar'));
    $response->assertSessionHas('flash.bannerStyle', 'info');

    // Invite should be marked as accepted
    $invite->refresh();
    expect($invite->accepted_at)->not->toBeNull();

    // Still attach missing workspace memberships for users already on the account.
    expect($this->workspace->members()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($user->fresh()->current_workspace_id)->toBe($this->workspace->id);
});

test('accept invite rejects invites whose workspaces were deleted', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);
    $personalAccountId = $user->account_id;

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $this->workspace->delete();

    $response = $this->actingAs($user)->post(route('app.invites.accept', $invite));

    // No current workspace → create (keeps flash; avoids EnsureHasWorkspace bounce).
    $response->assertRedirect(route('app.workspaces.create'));
    $response->assertSessionHas('flash.banner', __('settings.members.flash.invite_workspace_gone'));
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    expect(Invite::find($invite->id))->toBeNull();
    expect($user->fresh()->account_id)->toBe($personalAccountId);
    expect($user->fresh()->isAccountOwner())->toBeTrue();
});

test('accept invite does not demote an existing workspace admin', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.com',
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($user->id, ['role' => Role::Admin->value]);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
        'role' => Role::Viewer,
    ]);

    $this->actingAs($user)->post(route('app.invites.accept', $invite));

    $member = $this->workspace->members()->where('user_id', $user->id)->first();

    expect($member->pivot->role)->toBe(Role::Admin->value);
    expect($invite->fresh()->accepted_at)->not->toBeNull();
});

test('accepting an already accepted invite does not claim the workspace was deleted', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.com',
        'account_id' => $this->account->id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($user->id, ['role' => Role::Member->value]);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($user)->post(route('app.invites.accept', $invite));

    $response->assertRedirect(route('app.calendar'));
    $response->assertSessionHas('flash.banner', __('settings.members.flash.already_member'));
    $response->assertSessionHas('flash.bannerStyle', 'info');
});

test('invite redirect deletes a stranded non-owner', function () {
    [
        'member' => $member,
    ] = strandedMemberOnSharedAccount(
        owner: $this->owner,
        memberEmail: 'invitee@example.com',
    );

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $this->workspace->delete();

    $response = $this->actingAs($member)->post(route('app.invites.accept', $invite));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('flash.banner', __('settings.members.flash.invite_workspace_gone'));

    expect(User::find($member->id))->toBeNull();
});

test('decline of a dead invite deletes a stranded non-owner', function () {
    [
        'member' => $member,
    ] = strandedMemberOnSharedAccount(
        owner: $this->owner,
        memberEmail: 'invitee@example.com',
    );

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $this->workspace->delete();

    $response = $this->actingAs($member)->post(route('app.invites.decline', $invite));

    $response->assertRedirect(route('login'));
    $response->assertSessionHas('flash.banner', __('settings.members.flash.invite_workspace_gone'));

    expect(Invite::find($invite->id))->toBeNull();
    expect(User::find($member->id))->toBeNull();
});

test('decline invite requires authentication', function () {
    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
    ]);

    $response = $this->post(route('app.invites.decline', $invite));

    $response->assertRedirect(route('login'));
});

test('decline invite deletes the invite', function () {
    $user = User::factory()->create([
        'email' => 'invitee@example.com',
    ]);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->actingAs($user)->post(route('app.invites.decline', $invite));

    $response->assertRedirect(route('app.workspaces.create'));
    $response->assertSessionHas('flash.bannerStyle', 'info');

    // Invite should be deleted
    expect(Invite::find($invite->id))->toBeNull();
});

test('decline invite fails for wrong email', function () {
    $user = User::factory()->create([
        'email' => 'different@example.com',
    ]);

    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->owner->id,
        'email' => 'invitee@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->actingAs($user)->post(route('app.invites.decline', $invite));

    $response->assertRedirect(route('app.workspaces.create'));
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    // Invite should NOT be deleted
    expect(Invite::find($invite->id))->not->toBeNull();
});
