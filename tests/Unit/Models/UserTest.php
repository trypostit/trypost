<?php

declare(strict_types=1);

use App\Enums\Auth\SocialAuthProvider;
use App\Enums\User\Goal;
use App\Models\User;

test('isConnectedTo reflects whether the provider id column is set', function () {
    $user = User::factory()->make(['google_id' => 'g-123', 'github_id' => null]);

    expect($user->isConnectedTo(SocialAuthProvider::Google))->toBeTrue();
    expect($user->isConnectedTo(SocialAuthProvider::GitHub))->toBeFalse();
});

test('firstName returns the first token of the display name', function (string $name, string $expected) {
    expect(User::factory()->make(['name' => $name])->firstName())->toBe($expected);
})->with([
    ['Paulo Castellano', 'Paulo'],
    ['Ada', 'Ada'],
    ['  Marie  Curie  ', 'Marie'],
    ['', ''],
    ['   ', ''],
]);

test('hasCurrentGoals is true only when at least one stored goal still exists', function (?array $goals, bool $expected) {
    expect(User::factory()->make(['goals' => $goals])->hasCurrentGoals())->toBe($expected);
})->with([
    'null' => [null, false],
    'empty' => [[], false],
    'current' => [[Goal::SaveTime->value], true],
    'removed only' => [['team_collaboration', 'automate_api'], false],
    'mixed' => [['team_collaboration', Goal::SaveTime->value], true],
]);
