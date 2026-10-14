<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Auth\PasswordRequirements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

test('authentication page is displayed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('app.profile.edit', ['tab' => 'login']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/profile/Profile')
            ->has('sessions')
            ->where('hasPassword', true)
            ->has('connectedAccounts')
            ->where('passwordRequirements', PasswordRequirements::checklist())
        );
});

test('password can be updated', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('app.profile.edit', ['tab' => 'login']))
        ->put(route('app.profile.password.update'), [
            'current_password' => 'password',
            'password' => strongPassword(),
            'password_confirmation' => strongPassword(),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.profile.edit', ['tab' => 'login']));

    expect(Hash::check(strongPassword(), $user->refresh()->password))->toBeTrue();
});

test('password cannot be updated to a weak or compromised password', function (string $password) {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('app.profile.edit', ['tab' => 'login']))
        ->put(route('app.profile.password.update'), [
            'current_password' => 'password',
            'password' => $password,
            'password_confirmation' => $password,
        ])
        ->assertSessionHasErrors('password');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
})->with([
    'weak' => ['new-password'],
    'compromised' => [fn (): string => compromisedPassword()],
]);

test('correct password must be provided to update password', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('app.profile.edit', ['tab' => 'login']))
        ->put(route('app.profile.password.update'), [
            'current_password' => 'wrong-password',
            'password' => strongPassword(),
            'password_confirmation' => strongPassword(),
        ])
        ->assertSessionHasErrors('current_password')
        ->assertRedirect(route('app.profile.edit', ['tab' => 'login']));
});

test('password update requires authentication', function () {
    $this->put(route('app.profile.password.update'), [])
        ->assertRedirect(route('login'));
});

test('password must be confirmed', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('app.profile.edit', ['tab' => 'login']))
        ->put(route('app.profile.password.update'), [
            'current_password' => 'password',
            'password' => strongPassword(),
            'password_confirmation' => 'wrong-confirmation',
        ])
        ->assertSessionHasErrors('password');
});

test('user without a password can set one without current_password', function () {
    $user = User::factory()->create(['password' => null, 'google_id' => 'google-123']);

    $this->actingAs($user)
        ->from(route('app.profile.edit', ['tab' => 'login']))
        ->put(route('app.profile.password.update'), [
            'password' => strongPassword(),
            'password_confirmation' => strongPassword(),
        ])
        ->assertSessionHasNoErrors();

    expect(Hash::check(strongPassword(), $user->refresh()->password))->toBeTrue();
});

test('disconnect provider removes the link', function () {
    $user = User::factory()->create(['google_id' => 'google-123', 'password' => bcrypt('password')]);

    $this->actingAs($user)
        ->from(route('app.profile.edit', ['tab' => 'login']))
        ->delete(route('app.profile.connected-accounts.destroy', 'google'))
        ->assertRedirect(route('app.profile.edit', ['tab' => 'login']));

    expect($user->refresh()->google_id)->toBeNull();
});

test('disconnect provider blocked when it is the only sign-in method', function () {
    $user = User::factory()->create(['google_id' => 'google-123', 'password' => null]);

    $this->actingAs($user)
        ->from(route('app.profile.edit', ['tab' => 'login']))
        ->delete(route('app.profile.connected-accounts.destroy', 'google'))
        ->assertSessionHas('flash.error');

    expect($user->refresh()->google_id)->toBe('google-123');
});

test('disconnect provider rejects unknown provider', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('app.profile.connected-accounts.destroy', 'twitter'))
        ->assertNotFound();
});

test('destroy other sessions removes other rows for the user', function () {
    if (config('session.driver') !== 'database') {
        $this->markTestSkipped('Session driver is not database.');
    }

    $user = User::factory()->create();
    $sessionsTable = config('session.table', 'sessions');

    DB::table($sessionsTable)->insert([
        [
            'id' => 'other-session-id',
            'user_id' => $user->id,
            'ip_address' => '1.2.3.4',
            'user_agent' => 'OtherDevice',
            'payload' => '',
            'last_activity' => time(),
        ],
    ]);

    $this->actingAs($user)
        ->from(route('app.profile.edit', ['tab' => 'login']))
        ->delete(route('app.profile.sessions.destroy-others'), [
            'password' => 'password',
        ])
        ->assertRedirect(route('app.profile.edit', ['tab' => 'login']));

    expect(DB::table($sessionsTable)->where('id', 'other-session-id')->exists())->toBeFalse();
});
