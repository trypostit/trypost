<?php

declare(strict_types=1);

use App\Enums\User\SignInMethod;
use App\Models\User;

test('a user can sign in with every method they have', function (array $attributes, array $methods) {
    expect(SignInMethod::of((new User)->forceFill($attributes)))->toBe($methods);
})->with([
    'google only' => [['google_id' => 'g-1', 'github_id' => null, 'password' => null], [SignInMethod::Google]],
    'github only' => [['google_id' => null, 'github_id' => 'gh-1', 'password' => null], [SignInMethod::GitHub]],
    'email only' => [['google_id' => null, 'github_id' => null, 'password' => 'secret'], [SignInMethod::Email]],
    'an email user who linked Google since' => [['google_id' => 'g-1', 'github_id' => null, 'password' => 'secret'], [SignInMethod::Google, SignInMethod::Email]],
    'every method' => [['google_id' => 'g-1', 'github_id' => 'gh-1', 'password' => 'secret'], [SignInMethod::Google, SignInMethod::GitHub, SignInMethod::Email]],
]);

test('each sign-in method has its admin label', function () {
    expect(collect(SignInMethod::cases())->mapWithKeys(fn (SignInMethod $method): array => [$method->value => $method->label()])->all())
        ->toBe(['google' => 'Google', 'github' => 'GitHub', 'email' => 'Email and password']);
});
