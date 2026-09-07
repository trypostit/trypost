<?php

declare(strict_types=1);

use App\Enums\User\Locale;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(fn () => config()->set('trypost.self_hosted', false));

/**
 * @param  array<string, string>  $overrides
 */
function registerWithLocale(array $overrides = []): TestResponse
{
    return test()->post(route('register.store'), array_merge([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'Password123!',
        'locale' => 'en',
    ], $overrides));
}

test('the register screen starts the switcher on the default locale', function () {
    $this->get(route('register'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('auth/Register')
            ->where('locale', Locale::DEFAULT->value)
        );
});

test('registering stores the picked locale on the user', function () {
    registerWithLocale(['locale' => 'pt-BR'])->assertSessionHasNoErrors();

    expect(User::where('email', 'test@example.com')->first()->locale)
        ->toBe(Locale::PortugueseBrazil);
});

test('the locale is required, since the register form always submits one', function () {
    registerWithLocale(['locale' => null])->assertSessionHasErrors('locale');

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('an unsupported locale is rejected rather than stored', function () {
    registerWithLocale(['locale' => 'sv'])->assertSessionHasErrors('locale');

    expect(User::where('email', 'test@example.com')->exists())->toBeFalse();
});

test('validation errors come back in the picked locale', function () {
    $this->post(route('register.store'), [
        'name' => '',
        'email' => 'test@example.com',
        'password' => 'Password123!',
        'locale' => 'pt-BR',
    ])->assertSessionHasErrors([
        'name' => __('validation.required', ['attribute' => 'name'], 'pt-BR'),
    ]);
});

test('social registration always stores the default locale', function (string $provider, string $driver) {
    config([
        'trypost.google_auth_enabled' => true,
        'trypost.github_auth_enabled' => true,
    ]);

    $socialiteUser = new SocialiteUser;
    $socialiteUser->id = "{$provider}-locale";
    $socialiteUser->name = 'Social User';
    $socialiteUser->email = "{$provider}-locale@example.com";

    Socialite::shouldReceive('driver')
        ->with($driver)
        ->andReturn($mock = Mockery::mock());

    $mock->shouldReceive('user')->andReturn($socialiteUser);

    $this->get(route("auth.{$provider}.callback"));

    expect(User::where('email', "{$provider}-locale@example.com")->first()->locale)
        ->toBe(Locale::DEFAULT);
})->with([
    ['google', 'google-auth'],
    ['github', 'github'],
]);
