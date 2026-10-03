<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

// Index tests
test('workspaces index requires authentication', function () {
    $response = $this->get(route('app.workspaces.index'));

    $response->assertRedirect(route('login'));
});

test('workspaces index shows all workspaces for user', function () {
    $workspaces = Workspace::factory()->count(2)->create(['user_id' => $this->user->id]);
    foreach ($workspaces as $workspace) {
        $workspace->members()->attach($this->user->id, membershipPivot('member'));
    }

    $response = $this->actingAs($this->user)->get(route('app.workspaces.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workspaces/Index', false)
        ->has('workspaces', 3)
        ->has('currentWorkspaceId')
    );
});

// Create tests
test('create workspace requires authentication', function () {
    $response = $this->get(route('app.workspaces.create'));

    $response->assertRedirect(route('login'));
});

test('create workspace shows form for user with no workspaces', function () {
    // Delete existing workspace so user has none
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->delete();

    $response = $this->actingAs($this->user)->get(route('app.workspaces.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workspaces/Create', false)
        ->missing('availableContentLanguages')
        ->missing('availableFonts')
    );
});

test('create workspace shows form when user already has workspace in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.workspaces.create'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('workspaces/Create', false)
    );
});

// Store tests
test('store workspace requires authentication', function () {
    $response = $this->post(route('app.workspaces.store'), ['name' => 'Test Workspace']);

    $response->assertRedirect(route('login'));
});

test('store workspace creates first workspace', function () {
    // Delete existing workspace so user has none
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->delete();

    $response = $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => 'New Workspace',
    ]);

    $response->assertRedirect(route('app.workspace.channels'));

    $this->assertDatabaseHas('workspaces', [
        'name' => 'New Workspace',
        'user_id' => $this->user->id,
    ]);
});

test('store workspace creates second workspace in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => 'Second Workspace',
    ]);

    $response->assertRedirect(route('app.workspace.channels'));

    $this->assertDatabaseHas('workspaces', [
        'name' => 'Second Workspace',
        'user_id' => $this->user->id,
    ]);
});

test('store workspace validates name is required', function () {
    $response = $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors('name');
});

test('store workspace sets new workspace as current', function () {
    // Delete existing workspace so user has none
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->delete();

    $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => 'New Workspace',
    ]);

    $this->user->refresh();
    $newWorkspace = Workspace::where('name', 'New Workspace')->first();

    expect($this->user->current_workspace_id)->toBe($newWorkspace->id);
});

// Switch tests
test('switch workspace requires authentication', function () {
    $response = $this->post(route('app.workspaces.switch', $this->workspace));

    $response->assertRedirect(route('login'));
});

test('switch workspace changes current workspace', function () {
    $otherWorkspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $otherWorkspace->members()->attach($this->user->id, membershipPivot('member'));

    $response = $this->actingAs($this->user)->post(route('app.workspaces.switch', $otherWorkspace));

    $response->assertRedirect(route('app.calendar'));

    $this->user->refresh();
    expect($this->user->current_workspace_id)->toBe($otherWorkspace->id);
});

test('switch workspace returns 403 for workspace user does not belong to', function () {
    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);

    $response = $this->actingAs($this->user)->post(route('app.workspaces.switch', $otherWorkspace));

    $response->assertForbidden();
});

test('switch workspace returns 403 for personal workspace after joining another account', function () {
    $sharedOwner = User::factory()->create();
    $invitee = User::factory()->create();
    $personalAccountId = $invitee->account_id;

    $personalWorkspace = Workspace::factory()->create([
        'account_id' => $personalAccountId,
        'user_id' => $invitee->id,
    ]);
    $personalWorkspace->members()->attach($invitee->id, membershipPivot('admin'));

    $sharedWorkspace = Workspace::factory()->create([
        'account_id' => $sharedOwner->account_id,
        'user_id' => $sharedOwner->id,
    ]);
    $sharedWorkspace->members()->attach($invitee->id, membershipPivot('member'));
    $invitee->update([
        'account_id' => $sharedOwner->account_id,
        'current_workspace_id' => $sharedWorkspace->id,
    ]);

    $response = $this->actingAs($invitee)->post(route('app.workspaces.switch', $personalWorkspace));

    $response->assertForbidden();
    expect($invitee->fresh()->current_workspace_id)->toBe($sharedWorkspace->id);
});

test('workspace index only lists workspaces on the current account', function () {
    $sharedOwner = User::factory()->create();
    $invitee = User::factory()->create();
    $personalAccountId = $invitee->account_id;

    $personalWorkspace = Workspace::factory()->create([
        'account_id' => $personalAccountId,
        'user_id' => $invitee->id,
    ]);
    $personalWorkspace->members()->attach($invitee->id, membershipPivot('admin'));

    $sharedWorkspace = Workspace::factory()->create([
        'account_id' => $sharedOwner->account_id,
        'user_id' => $sharedOwner->id,
    ]);
    $sharedWorkspace->members()->attach($invitee->id, membershipPivot('member'));
    $invitee->update([
        'account_id' => $sharedOwner->account_id,
        'current_workspace_id' => $sharedWorkspace->id,
    ]);

    $response = $this->actingAs($invitee)->get(route('app.workspaces.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->has('workspaces', 1)
        ->where('workspaces.0.id', $sharedWorkspace->id));
});

// Settings tests
test('workspace settings requires authentication', function () {
    $response = $this->get(route('app.workspace.settings'));

    $response->assertRedirect(route('login'));
});

test('workspace settings shows the workspace settings page', function () {
    $response = $this->actingAs($this->user)->get(route('app.workspace.settings'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Workspace', false)
        ->has('workspace')
        ->where('isOnlyWorkspace', false)
        ->where('otherMemberCount', 0)
    );
});

test('workspace settings marks only workspace in saas mode', function () {
    config(['trypost.self_hosted' => false]);
    $this->user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.settings'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('isOnlyWorkspace', true)
    );
});

test('workspace settings does not mark only workspace when account has more than one', function () {
    config(['trypost.self_hosted' => false]);
    $this->user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.settings'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('isOnlyWorkspace', false)
    );
});

test('workspace settings otherMemberCount only includes members without another account workspace', function () {
    $stranded = User::factory()->create([
        'account_id' => $this->user->account_id,
    ]);
    $alsoOnOther = User::factory()->create([
        'account_id' => $this->user->account_id,
    ]);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);

    $this->workspace->members()->attach($stranded->id, membershipPivot('member'));
    $this->workspace->members()->attach($alsoOnOther->id, membershipPivot('member'));
    $otherWorkspace->members()->attach($alsoOnOther->id, membershipPivot('member'));

    $response = $this->actingAs($this->user)->get(route('app.workspace.settings'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('otherMemberCount', 1)
    );
});

test('workspace settings redirects to create if no workspace', function () {
    $this->user->update(['current_workspace_id' => null]);

    $response = $this->actingAs($this->user)->get(route('app.workspace.settings'));

    $response->assertRedirect(route('app.workspaces.create'));
});

// Update settings tests
test('update workspace settings requires authentication', function () {
    $response = $this->put(route('app.workspace.settings.update'), [
        'name' => 'Updated Name',
    ]);

    $response->assertRedirect(route('login'));
});

test('update workspace settings updates workspace and redirects back', function () {
    $response = $this->actingAs($this->user)
        ->from(route('app.workspace.settings'))
        ->put(route('app.workspace.settings.update'), [
            'name' => 'Updated Name',
        ]);

    $response->assertRedirect(route('app.workspace.settings'));

    $this->workspace->refresh();
    expect($this->workspace->name)->toBe('Updated Name');
});

test('update workspace settings updates the name when only the name is submitted', function () {
    $this->actingAs($this->user)
        ->from(route('app.workspace.settings'))
        ->put(route('app.workspace.settings.update'), [
            'name' => 'Name Only Update',
        ])
        ->assertRedirect(route('app.workspace.settings'))
        ->assertSessionHasNoErrors();

    expect($this->workspace->refresh()->name)->toBe('Name Only Update');
});

test('update workspace settings validates required fields', function () {
    $response = $this->actingAs($this->user)->put(route('app.workspace.settings.update'), [
        'name' => '',
    ]);

    $response->assertSessionHasErrors(['name']);
});

// Logo upload tests
test('upload workspace logo requires authentication', function () {
    $response = $this->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertRedirect(route('login'));
});

test('upload workspace logo succeeds with valid image', function () {
    $response = $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg', 200, 200),
    ]);

    $response->assertRedirect();

    $this->workspace->refresh();
    expect($this->workspace->has_logo)->toBeTrue();
    expect($this->workspace->logo_url)->not->toBeNull();
});

test('upload workspace logo validates file is an image', function () {
    $response = $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->create('document.pdf', 100),
    ]);

    $response->assertSessionHasErrors('photo');
});

test('upload workspace logo validates max size', function () {
    $response = $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg')->size(3000),
    ]);

    $response->assertSessionHasErrors('photo');
});

test('upload workspace logo requires authorization', function () {
    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkspace->members()->attach($otherUser->id, membershipPivot('member'));
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);
    $otherUser->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    // otherUser is account owner of their own account/workspace, so policy 'update' passes for their own workspace.
    // Switch their current workspace to the original $this->workspace (which they don't own) to trigger forbidden.
    $otherUser->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($otherUser)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg'),
    ]);

    $response->assertForbidden();
});

test('delete workspace logo requires authentication', function () {
    $response = $this->delete(route('app.workspace.delete-logo'));

    $response->assertRedirect(route('login'));
});

test('delete workspace logo succeeds', function () {
    // Upload first
    $this->actingAs($this->user)->post(route('app.workspace.upload-logo'), [
        'photo' => UploadedFile::fake()->image('logo.jpg', 200, 200),
    ]);

    $this->workspace->refresh();
    expect($this->workspace->has_logo)->toBeTrue();

    // Delete
    $response = $this->actingAs($this->user)->delete(route('app.workspace.delete-logo'));

    $response->assertRedirect();

    $this->workspace->refresh();
    expect($this->workspace->has_logo)->toBeFalse();
});

test('delete workspace logo requires authorization', function () {
    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkspace->members()->attach($otherUser->id, membershipPivot('member'));
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);
    $otherUser->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $otherUser->update(['current_workspace_id' => $this->workspace->id]);

    $response = $this->actingAs($otherUser)->delete(route('app.workspace.delete-logo'));

    $response->assertForbidden();
});

// Destroy tests
test('destroy workspace requires authentication', function () {
    $response = $this->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertRedirect(route('login'));
});

test('destroy workspace deletes the workspace', function () {
    Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $workspaceId = $this->workspace->id;

    $response = $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertRedirect(route('app.calendar'));
    $response->assertSessionHas('flash.success', __('workspaces.flash.deleted'));
    expect(Workspace::find($workspaceId))->toBeNull();
});

test('destroy workspace falls back to another account workspace when deleting current', function () {
    $other = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $this->user->refresh();
    expect($this->user->current_workspace_id)->toBe($other->id);
    expect($this->user->belongsToWorkspace($other))->toBeTrue();
});

test('destroy workspace reassigns current to another joined workspace', function () {
    $other = Workspace::factory()->create(['user_id' => $this->user->id]);
    $other->members()->attach($this->user->id, membershipPivot('member'));

    $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $this->user->refresh();
    expect($this->user->current_workspace_id)->toBe($other->id);
});

test('destroy workspace is blocked when it is the only workspace', function () {
    config(['trypost.self_hosted' => false]);
    $this->user->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
    $workspaceId = $this->workspace->id;

    $response = $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertSessionHas('flash.error', __('workspaces.cannot_delete_last'));
    expect(Workspace::find($workspaceId))->not->toBeNull();
});

test('destroy workspace allows deleting the only workspace in self-hosted mode', function () {
    config(['trypost.self_hosted' => true]);
    $workspaceId = $this->workspace->id;

    $response = $this->actingAs($this->user)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertRedirect(route('app.workspaces.create'));
    $response->assertSessionHas('flash.success', __('workspaces.flash.deleted'));
    expect(Workspace::find($workspaceId))->toBeNull();
    expect($this->user->fresh()->current_workspace_id)->toBeNull();
});

test('destroy workspace returns 403 for non-owner', function () {
    $otherUser = User::factory()->create([]);
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherUser->id]);
    $otherWorkspace->members()->attach($otherUser->id, membershipPivot('member'));
    $otherUser->update(['current_workspace_id' => $otherWorkspace->id]);
    $otherUser->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($otherUser)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertForbidden();
});

test('destroy workspace returns 403 for workspace admin', function () {
    Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);

    $admin = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($admin->id, membershipPivot('admin'));

    $response = $this->actingAs($admin)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertForbidden();
    expect(Workspace::find($this->workspace->id))->not->toBeNull();
});

test('destroy workspace returns 403 for workspace member', function () {
    Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);

    $member = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($member->id, membershipPivot('member'));

    $response = $this->actingAs($member)->delete(route('app.workspaces.destroy', $this->workspace));

    $response->assertForbidden();
    expect(Workspace::find($this->workspace->id))->not->toBeNull();
});

test('store persists the name and redirects to channels', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($user)->post(route('app.workspaces.store'), [
        'name' => 'Acme Inc',
    ]);

    $response->assertRedirect(route('app.workspace.channels'));

    expect(Workspace::where('name', 'Acme Inc')->sole()->name)->toBe('Acme Inc');
});

test('brand routes are gone', function () {
    expect(Route::has('app.workspace.brand'))->toBeFalse()
        ->and(Route::has('app.workspaces.autofill'))->toBeFalse();
});

test('store ignores retired brand fields', function () {
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->delete();

    $this->actingAs($this->user)->post(route('app.workspaces.store'), [
        'name' => 'Acme',
        'brand_description' => 'x',
        'content_language' => 'fr',
    ])->assertRedirect(route('app.workspace.channels'));

    expect(Workspace::where('name', 'Acme')->exists())->toBeTrue();
});

test('store redirects additional workspace to /accounts', function () {
    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    // First workspace already exists
    $existing = Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
    $existing->members()->attach($user->id, membershipPivot('member'));

    $response = $this->actingAs($user)->post(route('app.workspaces.store'), [
        'name' => 'Second Workspace',
    ]);

    $response->assertRedirect(route('app.workspace.channels'));
    expect(Workspace::where('account_id', $account->id)->count())->toBe(2);
});

test('store blocks a second workspace without an active subscription', function () {
    config(['trypost.self_hosted' => false]);

    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    // The account already owns one workspace and has no subscription, so a
    // direct POST must not bootstrap a second (billable) workspace.
    $existing = Workspace::factory()->create(['account_id' => $account->id, 'user_id' => $user->id]);
    $existing->members()->attach($user->id, membershipPivot('member'));

    $response = $this->actingAs($user)->post(route('app.workspaces.store'), [
        'name' => 'Second Workspace',
    ]);

    $response->assertRedirect(route('app.billing.index'));
    expect(Workspace::where('account_id', $account->id)->count())->toBe(1);
});
