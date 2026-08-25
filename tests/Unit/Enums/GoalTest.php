<?php

declare(strict_types=1);

use App\Enums\User\Goal;

test('containsCurrent is true only when at least one stored goal still exists', function (?array $goals, bool $expected) {
    expect(Goal::containsCurrent($goals))->toBe($expected);
})->with([
    'null' => [null, false],
    'empty' => [[], false],
    'current' => [[Goal::SaveTime->value], true],
    'removed only' => [['team_collaboration', 'automate_api'], false],
    'mixed' => [['team_collaboration', Goal::SaveTime->value], true],
]);
