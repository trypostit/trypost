<?php

declare(strict_types=1);

use App\Enums\User\SignupMethod;
use App\Models\User;

test('a user signed up with Google, GitHub or an email and a password', function (array $attributes, SignupMethod $method) {
    expect(SignupMethod::of((new User)->forceFill($attributes)))->toBe($method);
})->with([
    'google' => [['google_id' => 'g-1', 'github_id' => 'gh-1'], SignupMethod::Google],
    'github' => [['google_id' => null, 'github_id' => 'gh-1'], SignupMethod::GitHub],
    'email' => [['google_id' => null, 'github_id' => null], SignupMethod::Email],
]);

test('each signup method has its backoffice label', function () {
    expect(collect(SignupMethod::cases())->mapWithKeys(fn (SignupMethod $method): array => [$method->value => $method->label()])->all())
        ->toBe(['google' => 'Google', 'github' => 'GitHub', 'email' => 'Email and password']);
});
