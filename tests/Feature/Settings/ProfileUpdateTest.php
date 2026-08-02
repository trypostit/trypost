<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\Invite;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Cashier\Subscription;

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

test('owner deleting profile revokes their passport tokens', function () {
    $owner = User::factory()->create();
    $token = $owner->createToken('API Key')->token;

    $this->actingAs($owner)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    expect(User::find($owner->id))->toBeNull();
    expect(AccessToken::find($token->id)->revoked)->toBeTrue();
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

test('deleting account deletes members who belong to the shared account', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        setMemberCurrent: true,
    );
    $memberToken = $member->createToken('Member API')->token;

    $this
        ->actingAs($owner)
        ->delete(route('app.profile.destroy'), [
            'password' => 'password',
        ]);

    expect(User::find($member->id))->toBeNull();
    expect(AccessToken::find($memberToken->id)->revoked)->toBeTrue();
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

test('member deleting profile does not delete shared workspaces they created', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create(['account_id' => $owner->account_id]);

    $sharedWorkspace = Workspace::factory()->create([
        'account_id' => $owner->account_id,
        'user_id' => $member->id,
    ]);
    $sharedWorkspace->members()->attach($owner->id, ['role' => Role::Admin->value]);
    $sharedWorkspace->members()->attach($member->id, ['role' => Role::Member->value]);

    $this->actingAs($member)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    expect(User::find($member->id))->toBeNull();
    expect(Workspace::find($sharedWorkspace->id))->not->toBeNull();
    expect(Account::find($owner->account_id))->not->toBeNull();
});

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

test('owner deleting profile deletes remaining members of the account', function () {
    [
        'owner' => $owner,
        'member' => $member,
        'shared_workspaces' => [$workspace],
    ] = strandedMemberOnSharedAccount(
        sharedWorkspaces: 1,
        setMemberCurrent: true,
    );
    $accountId = $owner->account_id;

    $this->actingAs($owner)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    expect(Account::find($accountId))->toBeNull();
    expect(User::find($member->id))->toBeNull();
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

test('owner deleting profile clears media for account workspaces not owned by user_id', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $accountId = $owner->account_id;

    $memberCreated = Workspace::factory()->create([
        'account_id' => $accountId,
        'user_id' => User::factory()->create(['account_id' => $accountId])->id,
    ]);
    $media = $memberCreated->addMedia(
        UploadedFile::fake()->image('logo.jpg'),
        'logo',
    );
    $mediaPath = $media->path;
    Storage::assertExists($mediaPath);

    $this->actingAs($owner)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    expect(Account::find($accountId))->toBeNull();
    expect(Workspace::find($memberCreated->id))->toBeNull();
    expect(Media::find($media->id))->toBeNull();
    Storage::assertMissing($mediaPath);
});

test('owner deleting profile clears avatar media', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $avatar = $owner->addMedia(
        UploadedFile::fake()->image('avatar.jpg', 200, 200),
        'avatar',
    );
    $avatarPath = $avatar->path;
    Storage::assertExists($avatarPath);

    $this->actingAs($owner)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    expect($owner->fresh())->toBeNull();
    expect(Media::find($avatar->id))->toBeNull();
    Storage::assertMissing($avatarPath);
});

test('owner account delete aborts when stripe cancel fails', function () {
    Storage::fake();

    $owner = User::factory()->create();
    $account = $owner->account;
    $accountId = $account->id;

    $member = User::factory()->create();
    $member->account?->delete();
    $member->update(['account_id' => $accountId]);

    $workspace = Workspace::factory()->create([
        'account_id' => $accountId,
        'user_id' => $owner->id,
    ]);
    $workspace->members()->attach($owner->id, ['role' => Role::Member->value]);
    $workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    $media = $workspace->addMedia(
        UploadedFile::fake()->image('logo.jpg'),
        'logo',
    );
    $mediaPath = $media->path;
    Storage::assertExists($mediaPath);

    $invite = Invite::factory()->create([
        'account_id' => $accountId,
        'invited_by' => $owner->id,
        'workspaces' => [$workspace->id],
    ]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $mockSubscription = Mockery::mock(Subscription::class);
    $mockSubscription->shouldReceive('ended')->andReturnFalse();
    $mockSubscription->shouldReceive('cancelNow')
        ->once()
        ->andThrow(new RuntimeException('stripe unavailable'));

    $mockAccount = Mockery::mock($account)->makePartial();
    $mockAccount->shouldReceive('subscription')
        ->with(Account::SUBSCRIPTION_NAME)
        ->andReturn($mockSubscription);
    $mockAccount->shouldReceive('delete')->never();

    $owner->setRelation('account', $mockAccount);

    $response = $this->actingAs($owner)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    $response->assertRedirect(route('app.profile.edit'));
    $response->assertSessionHas('flash.banner', __('settings.flash.delete_failed_billing'));
    $response->assertSessionHas('flash.bannerStyle', 'danger');

    expect($owner->fresh())->not->toBeNull();
    expect(User::find($member->id))->not->toBeNull();
    expect(Account::find($accountId))->not->toBeNull();
    expect(Account::find($accountId)->subscriptions()->count())->toBe(1);
    expect(Workspace::find($workspace->id))->not->toBeNull();
    expect(Media::find($media->id))->not->toBeNull();
    expect(Invite::find($invite->id))->not->toBeNull();
    Storage::assertExists($mediaPath);
});

test('account delete cancels incomplete stripe subscriptions that are not subscribed', function () {
    $owner = User::factory()->create();
    $account = $owner->account;
    $accountId = $account->id;
    $member = User::factory()->create();
    $member->update(['account_id' => $accountId]);

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_incomplete_'.fake()->uuid(),
        'stripe_status' => 'incomplete',
        'stripe_price' => 'price_123',
    ]);

    expect($account->fresh()->subscribed(Account::SUBSCRIPTION_NAME))->toBeFalse();

    $mockSubscription = Mockery::mock(Subscription::class);
    $mockSubscription->shouldReceive('ended')->andReturnFalse();
    $mockSubscription->shouldReceive('cancelNow')
        ->once()
        ->andThrow(new RuntimeException('stripe unavailable'));

    $mockAccount = Mockery::mock($account)->makePartial();
    $mockAccount->shouldReceive('subscription')
        ->with(Account::SUBSCRIPTION_NAME)
        ->andReturn($mockSubscription);
    $mockAccount->shouldReceive('delete')->never();

    $owner->setRelation('account', $mockAccount);

    $response = $this->actingAs($owner)->delete(route('app.profile.destroy'), [
        'password' => 'password',
    ]);

    $response->assertRedirect(route('app.profile.edit'));
    $response->assertSessionHas('flash.banner', __('settings.flash.delete_failed_billing'));

    expect($owner->fresh())->not->toBeNull();
    expect(User::find($member->id))->not->toBeNull();
    expect(Account::find($accountId))->not->toBeNull();
});
