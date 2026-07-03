<?php

declare(strict_types=1);

use App\Models\User;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

test('honeypot field silently rejects the registration', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'Bot',
        'email' => 'bot@example.com',
        'password' => 'Password!123',
        'contact_time' => 'http://spam.example',
    ]);

    $response->assertRedirect(route('login'));
    expect(User::where('email', 'bot@example.com')->exists())->toBeFalse();
});

test('an empty honeypot lets a real registration through', function () {
    config(['trypost.security.max_registrations_per_ip_per_day' => 0]);

    // Humans: the front-end clears the honeypot, so it arrives empty (or absent).
    $this->post(route('register.store'), [
        'name' => 'Jane',
        'email' => 'jane@example.com',
        'password' => 'Password!123',
        'contact_time' => '',
    ])->assertRedirect(route('register.success'));

    expect(User::where('email', 'jane@example.com')->exists())->toBeTrue();
});

test('disposable email domains are rejected', function () {
    config(['trypost.security.block_disposable_emails' => true]);

    $this->post(route('register.store'), [
        'name' => 'Farmer',
        'email' => 'x@mailinator.com',
        'password' => 'Password!123',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'x@mailinator.com')->exists())->toBeFalse();
});

test('extra disposable domains from config are also rejected', function () {
    config([
        'trypost.security.block_disposable_emails' => true,
        'trypost.security.extra_disposable_domains' => ['spamcustom.dev'],
    ]);

    $this->post(route('register.store'), [
        'name' => 'Farmer',
        'email' => 'x@spamcustom.dev',
        'password' => 'Password!123',
    ])->assertSessionHasErrors('email');
});

test('registrations beyond the per-ip daily quota are rejected', function () {
    config(['trypost.security.max_registrations_per_ip_per_day' => 2]);

    User::factory()->count(2)->create(['registration_ip' => '127.0.0.1']);

    $this->post(route('register.store'), [
        'name' => 'Third Account',
        'email' => 'third@example.com',
        'password' => 'Password!123',
    ])->assertSessionHasErrors('email');

    expect(User::where('email', 'third@example.com')->exists())->toBeFalse();
});

test('the per-ip quota can be disabled with zero', function () {
    config(['trypost.security.max_registrations_per_ip_per_day' => 0]);

    User::factory()->count(5)->create(['registration_ip' => '127.0.0.1']);

    $this->post(route('register.store'), [
        'name' => 'Free Account',
        'email' => 'free@example.com',
        'password' => 'Password!123',
    ])->assertRedirect(route('register.success'));
});

test('the register route is throttled', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('register.store'), ['name' => '', 'email' => '', 'password' => '']);
    }

    $this->post(route('register.store'), [
        'name' => 'Someone',
        'email' => 'someone@example.com',
        'password' => 'Password!123',
    ])->assertStatus(429);
});

test('malformed emails are rejected at registration', function () {
    foreach (['noatsign', 'space @b.com', 'a@b', 'user@localhost'] as $bad) {
        $this->post(route('register.store'), [
            'name' => 'John',
            'email' => $bad,
            'password' => 'Password!123',
        ])->assertSessionHasErrors('email');
    }

    expect(User::count())->toBe(0);
});

test('a real email passes the strict rule', function () {
    config(['trypost.security.max_registrations_per_ip_per_day' => 0]);

    $this->post(route('register.store'), [
        'name' => 'Real User',
        'email' => 'real@company.example',
        'password' => 'Password!123',
    ])->assertRedirect(route('register.success'));

    expect(User::where('email', 'real@company.example')->exists())->toBeTrue();
});
