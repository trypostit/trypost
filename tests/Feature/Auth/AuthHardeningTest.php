<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

test('an unverified user is gated to the verification prompt before the app', function () {
    config(['trypost.self_hosted' => false, 'trypost.security.require_email_verification' => true]);

    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)
        ->get(route('app.calendar'))
        ->assertRedirect(route('verification.notice'));
});

test('a verified user passes the gate', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertRedirect(route('app.calendar'));
});

test('the verification prompt itself renders without a redirect loop', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create(['email_verified_at' => null]);

    $this->actingAs($user)
        ->get(route('verification.notice'))
        ->assertOk();
});

test('forgot-password responds uniformly whether or not the email exists', function () {
    $existing = $this->post(route('password.email'), ['email' => User::factory()->create()->email]);
    $missing = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

    $existing->assertSessionHasNoErrors()->assertSessionHas('status');
    $missing->assertSessionHasNoErrors()->assertSessionHas('status');

    expect(session('status'))->toBe(__('passwords.sent_uniform'));
});

test('resetting the password wipes every active database session', function () {
    config(['session.driver' => 'database']);

    $user = User::factory()->create();
    $token = Password::createToken($user);

    DB::table(config('session.table', 'sessions'))->insert([
        'id' => 'attacker-session',
        'user_id' => $user->id,
        'ip_address' => '10.0.0.1',
        'user_agent' => 'evil',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'Password!1234',
        'password_confirmation' => 'Password!1234',
    ])->assertRedirect(route('login'));

    expect(DB::table(config('session.table', 'sessions'))->where('id', 'attacker-session')->exists())->toBeFalse();
});
