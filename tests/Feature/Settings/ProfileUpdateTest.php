<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('app.profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put(route('app.profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put(route('app.profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can update their locale via cookie', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('app.posts.index'))
        ->put(route('app.profile.language'), [
            'locale' => 'es',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.posts.index'));

    $response->assertCookieNotExpired('locale');
});

test('user cannot update locale with invalid code', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put(route('app.profile.language'), [
            'locale' => 'invalid',
        ]);

    $response->assertSessionHasErrors('locale');
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('app.profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('app.profile.edit'))
        ->delete(route('app.profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('app.profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('deleting account updates members current_workspace_id when their workspace is deleted', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();

    // Create a workspace owned by the owner
    $workspace = Workspace::factory()->create(['user_id' => $owner->id]);
    $owner->workspaces()->attach($workspace->id, ['role' => Role::Member->value]);
    $owner->update(['current_workspace_id' => $workspace->id]);

    // Add member to the workspace and set it as their current
    $member->workspaces()->attach($workspace->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $workspace->id]);

    // Verify setup
    expect($member->current_workspace_id)->toBe($workspace->id);

    // Owner deletes their account
    $this
        ->actingAs($owner)
        ->delete(route('app.profile.destroy'), [
            'password' => 'password',
        ]);

    // Verify member's current_workspace_id is updated to null (since they have no other workspace)
    expect($member->fresh()->current_workspace_id)->toBeNull();
});

test('deleting account updates members current_workspace_id to another workspace when available', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $otherOwner = User::factory()->create();

    // Create workspace owned by the owner being deleted
    $workspaceToDelete = Workspace::factory()->create(['user_id' => $owner->id]);
    $owner->workspaces()->attach($workspaceToDelete->id, ['role' => Role::Member->value]);
    $owner->update(['current_workspace_id' => $workspaceToDelete->id]);

    // Create another workspace owned by a different user
    $otherWorkspace = Workspace::factory()->create(['user_id' => $otherOwner->id]);
    $otherOwner->workspaces()->attach($otherWorkspace->id, ['role' => Role::Member->value]);

    // Add member to both workspaces
    $member->workspaces()->attach($workspaceToDelete->id, ['role' => Role::Member->value]);
    $member->workspaces()->attach($otherWorkspace->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $workspaceToDelete->id]);

    // Verify setup
    expect($member->current_workspace_id)->toBe($workspaceToDelete->id);

    // Owner deletes their account
    $this
        ->actingAs($owner)
        ->delete(route('app.profile.destroy'), [
            'password' => 'password',
        ]);

    // Verify member's current_workspace_id is updated to the other workspace
    expect($member->fresh()->current_workspace_id)->toBe($otherWorkspace->id);
});

test('user can upload profile photo', function () {
    Storage::fake();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('app.profile.upload-photo'), [
            'photo' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
        ]);

    $response->assertRedirect();

    $user->refresh();
    expect($user->has_photo)->toBeTrue();
    expect($user->photo_url)->not->toBeNull();
});

test('user cannot upload non-image file as photo', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('app.profile.upload-photo'), [
            'photo' => UploadedFile::fake()->create('document.pdf', 100),
        ]);

    $response->assertSessionHasErrors('photo');
});

test('user can delete profile photo', function () {
    Storage::fake();

    $user = User::factory()->create();

    // Upload first
    $this->actingAs($user)->post(route('app.profile.upload-photo'), [
        'photo' => UploadedFile::fake()->image('avatar.jpg', 200, 200),
    ]);

    $user->refresh();
    expect($user->has_photo)->toBeTrue();

    // Delete
    $response = $this->actingAs($user)->delete(route('app.profile.delete-photo'));

    $response->assertRedirect();

    $user->refresh();
    expect($user->has_photo)->toBeFalse();
});

test('user cannot upload photo exceeding max size', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post(route('app.profile.upload-photo'), [
            'photo' => UploadedFile::fake()->image('large.jpg')->size(6000),
        ]);

    $response->assertSessionHasErrors('photo');
});

test('delete account requires authentication', function () {
    $response = $this->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    $response->assertRedirect(route('login'));
});

test('member deleting profile does NOT destroy the shared account', function (bool $selfHosted) {
    config()->set('trypost.self_hosted', $selfHosted);

    $owner = User::factory()->create();
    $member = User::factory()->create(['account_id' => $owner->account_id]);

    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $owner->workspaces()->attach($workspace->id, ['role' => Role::Member->value]);
    $member->workspaces()->attach($workspace->id, ['role' => Role::Member->value]);

    $this->actingAs($member)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    expect($member->fresh())->toBeNull();
    expect(Account::find($owner->account_id))->not->toBeNull();
    expect(Workspace::find($workspace->id))->not->toBeNull();
    expect($owner->fresh())->not->toBeNull();
})->with([true, false]);

test('member deleting profile detaches them from workspaces', function (bool $selfHosted) {
    config()->set('trypost.self_hosted', $selfHosted);

    $owner = User::factory()->create();
    $member = User::factory()->create(['account_id' => $owner->account_id]);

    $workspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $owner->id,
    ]);
    $member->workspaces()->attach($workspace->id, ['role' => Role::Member->value]);

    $this->actingAs($member)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    expect($workspace->fresh()->members()->where('users.id', $member->id)->exists())->toBeFalse();
})->with([true, false]);

test('owner deleting profile rehomes remaining members to a personal account', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $personalAccountId = $member->account_id;
    $member->update(['account_id' => $owner->account_id]);
    $accountId = $owner->account_id;

    $workspace = Workspace::factory()->create([
        'account_id' => $accountId,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, ['role' => Role::Member->value]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($owner)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    $member->refresh();

    expect(Account::find($accountId))->toBeNull();
    expect($member->account_id)->toBe($personalAccountId);
    expect($member->isAccountOwner())->toBeTrue();
    expect($member->current_workspace_id)->toBeNull();
});

test('owner deleting profile destroys the account and cascades', function (bool $selfHosted) {
    config()->set('trypost.self_hosted', $selfHosted);

    $owner = User::factory()->create();
    $accountId = $owner->account_id;

    $workspace = Workspace::factory()->create([
        'account_id' => $accountId,
        'user_id' => $owner->id,
    ]);
    $owner->workspaces()->attach($workspace->id, ['role' => Role::Member->value]);

    $this->actingAs($owner)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    expect(Account::find($accountId))->toBeNull();
    expect(Workspace::find($workspace->id))->toBeNull();
})->with([true, false]);
