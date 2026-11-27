<?php

declare(strict_types=1);

use Illuminate\Validation\Rules\Password;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

test('the register page lists every password requirement', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/auth/Register')
            ->where('passwordRequirements', [
                ['key' => 'min', 'value' => 12],
                ['key' => 'mixed_case'],
                ['key' => 'number'],
                ['key' => 'symbol'],
            ])
        );
})->with(['testing', 'local', 'production']);

test('a password missing a requirement is rejected', function (string $password) {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => $password,
        'locale' => 'en',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
})->with([
    'too short' => ['Short1!aB'],
    'no uppercase' => ['lowercase-only-123!'],
    'no lowercase' => ['UPPERCASE-ONLY-123!'],
    'no number' => ['NoNumbersHere-ok!'],
    'no symbol' => ['NoSymbolsHere123'],
    'laravel default' => ['password'],
]);

test('a compromised password is rejected', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => compromisedPassword(),
        'locale' => 'en',
    ])->assertSessionHasErrors('password');

    $this->assertGuest();
});

test('a password meeting every requirement is accepted', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => strongPassword(),
        'locale' => 'en',
    ])->assertSessionHasNoErrors();

    $this->assertAuthenticated();
});

test('the same password rule applies in every environment', function (string $environment) {
    app()->detectEnvironment(fn (): string => $environment);

    expect(Password::default()->appliedRules())->toMatchArray([
        'min' => 12,
        'mixedCase' => true,
        'letters' => true,
        'numbers' => true,
        'symbols' => true,
        'uncompromised' => true,
    ]);
})->with(['testing', 'local', 'production']);
