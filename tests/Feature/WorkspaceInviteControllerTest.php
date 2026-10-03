<?php

declare(strict_types=1);

use App\Mail\WorkspaceInvite as WorkspaceInviteMail;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\Invite;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

beforeEach(function () {
    Mail::fake();
    config(['trypost.self_hosted' => true]);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

// Index tests
test('members index requires authentication', function () {
    $response = $this->get(route('app.members'));

    $response->assertRedirect(route('login'));
});

test('members page shows members and invites', function () {
    Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->user->id,
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->actingAs($this->user)->get(route('app.members'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Members', false)
        ->has('workspace')
        ->has('members')
        ->has('members.0', fn ($member) => $member
            ->hasAll(['id', 'name', 'email', 'photo_url', 'is_admin', 'requires_approval'])
        )
        ->has('invites')
        ->has('owner')
        ->missing('roles')
    );
});

// Store invite tests
test('store invite requires authentication', function () {
    $response = $this->post(route('app.invites.store'), [
        'email' => 'test@example.com',
        ...membershipPivot('member'),
    ]);

    $response->assertRedirect(route('login'));
});

test('store invite creates invite and sends email', function () {
    $response = $this->actingAs($this->user)->post(route('app.invites.store'), [
        'email' => 'newmember@example.com',
        ...membershipPivot('member'),
    ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('invites', [
        'account_id' => $this->account->id,
        'email' => 'newmember@example.com',
    ]);

    Mail::assertQueued(WorkspaceInviteMail::class);
});

test('store invite blocks an email that already belongs to a registered user', function () {
    User::factory()->create(['email' => 'existing@example.com']);

    $response = $this->actingAs($this->user)->post(route('app.invites.store'), [
        'email' => 'existing@example.com',
        ...membershipPivot('member'),
    ]);

    $response->assertSessionHasErrors('email');
    $this->assertDatabaseMissing('invites', ['email' => 'existing@example.com']);
    Mail::assertNothingQueued();
});

test('store invite requires both access flags', function () {
    $this->actingAs($this->user)->post(route('app.invites.store'), [
        'email' => 'newmember@example.com',
    ])->assertSessionHasErrors(['is_admin', 'requires_approval']);

    $this->assertDatabaseMissing('invites', ['email' => 'newmember@example.com']);
});

test('store invite persists the chosen access', function () {
    $this->actingAs($this->user)->post(route('app.invites.store'), [
        'email' => 'approval@example.com',
        'is_admin' => '0',
        'requires_approval' => '1',
    ])->assertSessionHasNoErrors();

    $invite = Invite::query()->where('email', 'approval@example.com')->sole();

    expect($invite->is_admin)->toBeFalse()
        ->and($invite->requires_approval)->toBeTrue();
});

test('an admin invite never needs approval', function () {
    $this->actingAs($this->user)->post(route('app.invites.store'), [
        'email' => 'admin@example.com',
        'is_admin' => '1',
        'requires_approval' => '1',
    ])->assertSessionHasNoErrors();

    $invite = Invite::query()->where('email', 'admin@example.com')->sole();

    expect($invite->is_admin)->toBeTrue()
        ->and($invite->requires_approval)->toBeFalse();
});

test('store invite fails if invite already exists', function () {
    Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->user->id,
        'email' => 'existing@example.com',
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->actingAs($this->user)->post(route('app.invites.store'), [
        'email' => 'existing@example.com',
        ...membershipPivot('member'),
    ]);

    $response->assertSessionHasErrors('email');
});

test('store invite fails if user is already member', function () {
    $member = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $response = $this->actingAs($this->user)->post(route('app.invites.store'), [
        'email' => $member->email,
        ...membershipPivot('member'),
    ]);

    $response->assertSessionHasErrors('email');
});

// Destroy invite tests
test('destroy invite requires authentication', function () {
    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->user->id,
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->delete(route('app.invites.destroy', $invite));

    $response->assertRedirect(route('login'));
});

test('destroy invite deletes invite', function () {
    $invite = Invite::factory()->create([
        'account_id' => $this->account->id,
        'invited_by' => $this->user->id,
        'workspaces' => [$this->workspace->id],
    ]);

    $response = $this->actingAs($this->user)->delete(route('app.invites.destroy', $invite));

    $response->assertRedirect();
    expect(Invite::find($invite->id))->toBeNull();
});

test('destroy invite returns 404 for other account invite', function () {
    $otherAccount = Account::factory()->create();
    $invite = Invite::factory()->create([
        'account_id' => $otherAccount->id,
        'workspaces' => [],
    ]);

    $response = $this->actingAs($this->user)->delete(route('app.invites.destroy', $invite));

    $response->assertNotFound();
});

// Remove member tests
test('remove member requires authentication', function () {
    $member = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $response = $this->delete(route('app.members.remove', $member));

    $response->assertRedirect(route('login'));
});

test('remove member removes user from workspace', function () {
    $member = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $response = $this->actingAs($this->user)->delete(route('app.members.remove', $member));

    $response->assertRedirect();
    expect($this->workspace->hasMember($member))->toBeFalse();
});

test('remove member deletes stranded members', function () {
    [
        'member' => $member,
    ] = strandedMemberOnSharedAccount(
        owner: $this->user,
        setMemberCurrent: false,
    );
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $this->actingAs($this->user)->delete(route('app.members.remove', $member));

    expect($this->workspace->hasMember($member))->toBeFalse();
    expect(User::find($member->id))->toBeNull();
});

test('remove member fails for owner', function () {
    $response = $this->actingAs($this->user)->delete(route('app.members.remove', $this->user));

    $response->assertSessionHasErrors('member');
});

// Update member tests
function inviteControllerMemberFlags(Workspace $workspace, User $member): array
{
    $pivot = $workspace->members()->where('user_id', $member->id)->first()->pivot;

    return ['is_admin' => (bool) $pivot->is_admin, 'requires_approval' => (bool) $pivot->requires_approval];
}

test('update member requires authentication', function () {
    $member = workspaceMember($this->workspace);

    $this->put(route('app.members.update', $member), membershipPivot('admin'))
        ->assertRedirect(route('login'));
});

test('update member makes a member an admin', function () {
    $member = workspaceMember($this->workspace);

    $this->actingAs($this->user)
        ->put(route('app.members.update', $member), ['is_admin' => '1', 'requires_approval' => '0'])
        ->assertRedirect()
        ->assertSessionMissing('flash.banner');

    expect(inviteControllerMemberFlags($this->workspace, $member))->toBe(['is_admin' => true, 'requires_approval' => false]);
});

test('update member makes a member need approval', function () {
    $member = workspaceMember($this->workspace);

    $this->actingAs($this->user)
        ->put(route('app.members.update', $member), ['is_admin' => '0', 'requires_approval' => '1'])
        ->assertRedirect();

    expect(inviteControllerMemberFlags($this->workspace, $member))->toBe(['is_admin' => false, 'requires_approval' => true]);
});

test('an admin is never stored as needing approval', function () {
    $member = workspaceMember($this->workspace, 'approval');

    $this->actingAs($this->user)
        ->put(route('app.members.update', $member), ['is_admin' => '1', 'requires_approval' => '1'])
        ->assertRedirect();

    expect(inviteControllerMemberFlags($this->workspace, $member))->toBe(['is_admin' => true, 'requires_approval' => false]);
});

test('an admin cannot change their own access', function () {
    $admin = workspaceMember($this->workspace, 'admin');

    $this->actingAs($admin)
        ->put(route('app.members.update', $admin), ['is_admin' => '0', 'requires_approval' => '1'])
        ->assertSessionHasErrors(['is_admin' => __('settings.members.errors.cannot_change_own_access')]);

    expect(inviteControllerMemberFlags($this->workspace, $admin))->toBe(['is_admin' => true, 'requires_approval' => false]);
});

test('an admin cannot remove themselves', function () {
    $admin = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($admin->id, membershipPivot('admin'));
    $admin->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($admin)->delete(route('app.members.remove', $admin));

    $response->assertSessionHasErrors('member');
    expect($this->workspace->members()->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('removing admin access revokes the member api keys', function () {
    $member = workspaceMember($this->workspace, 'admin');
    $result = $member->createToken('Admin Key');
    $token = AccessToken::query()->findOrFail($result->token->id);
    $token->forceFill(['workspace_id' => $this->workspace->id])->saveQuietly();

    $this->actingAs($this->user)
        ->put(route('app.members.update', $member), ['is_admin' => '0', 'requires_approval' => '0'])
        ->assertRedirect();

    expect(inviteControllerMemberFlags($this->workspace, $member))->toBe(['is_admin' => false, 'requires_approval' => false])
        ->and($token->fresh()->revoked)->toBeTrue();
});

test('requiring approval keeps mcp oauth grants and still lets the member write posts', function () {
    $member = workspaceMember($this->workspace);
    $oauth = mcpAccessToken($member, mcpOauthClient(), $this->workspace);
    $refreshTokenId = (string) Str::uuid();
    DB::table('oauth_refresh_tokens')->insert([
        'id' => $refreshTokenId,
        'access_token_id' => $oauth->id,
        'revoked' => false,
        'expires_at' => now()->addDay(),
    ]);

    $this->actingAs($this->user)
        ->put(route('app.members.update', $member), ['is_admin' => '0', 'requires_approval' => '1'])
        ->assertRedirect();

    expect($oauth->fresh()->revoked)->toBeFalse()
        ->and((bool) DB::table('oauth_refresh_tokens')->where('id', $refreshTokenId)->value('revoked'))->toBeFalse()
        ->and($member->fresh()->can('createPost', $this->workspace))->toBeTrue()
        ->and($member->fresh()->can('publishDirectly', $this->workspace))->toBeFalse();
});

test('the account owner cannot be demoted', function () {
    $admin = workspaceMember($this->workspace, 'admin');

    $this->actingAs($admin)
        ->put(route('app.members.update', $this->user), ['is_admin' => '0', 'requires_approval' => '1'])
        ->assertSessionHasErrors(['is_admin' => __('settings.members.errors.cannot_change_owner_access')]);

    expect($this->user->fresh()->isWorkspaceAdmin($this->workspace))->toBeTrue()
        ->and($this->user->fresh()->requiresApprovalIn($this->workspace))->toBeFalse()
        ->and(inviteControllerMemberFlags($this->workspace, $this->user))->toBe(['is_admin' => true, 'requires_approval' => false]);
});

test('update member validates both flags', function () {
    $member = workspaceMember($this->workspace);

    $this->actingAs($this->user)
        ->put(route('app.members.update', $member), ['is_admin' => 'maybe'])
        ->assertSessionHasErrors(['is_admin', 'requires_approval']);
});

test('update member requires team management', function () {
    $member = workspaceMember($this->workspace);
    $nonAdmin = workspaceMember($this->workspace);

    $this->actingAs($nonAdmin)
        ->put(route('app.members.update', $member), membershipPivot('admin'))
        ->assertForbidden();
});

test('update member is forbidden before validation for non admins', function () {
    $member = workspaceMember($this->workspace);
    $nonAdmin = workspaceMember($this->workspace);

    $this->actingAs($nonAdmin)
        ->put(route('app.members.update', $member), ['is_admin' => 'maybe'])
        ->assertForbidden();
});

test('store invite validates email is required', function () {
    $response = $this->actingAs($this->user)->post(route('app.invites.store'), []);

    $response->assertSessionHasErrors('email');
});

test('store invite validates email format', function () {
    $response = $this->actingAs($this->user)->post(route('app.invites.store'), [
        'email' => 'not-an-email',
    ]);

    $response->assertSessionHasErrors('email');
});
