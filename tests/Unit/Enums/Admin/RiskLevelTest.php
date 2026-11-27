<?php

declare(strict_types=1);

use App\Enums\Admin\RiskLevel;

test('a score reads as a risk level, none without a signal', function (int $score, ?RiskLevel $level) {
    expect(RiskLevel::fromScore($score))->toBe($level);
})->with([
    'no signal' => [0, null],
    'one light signal' => [1, RiskLevel::Low],
    'medium from 2' => [RiskLevel::MEDIUM_SCORE, RiskLevel::Medium],
    'still medium' => [RiskLevel::HIGH_SCORE - 1, RiskLevel::Medium],
    'high from 4' => [RiskLevel::HIGH_SCORE, RiskLevel::High],
    'high above' => [9, RiskLevel::High],
]);
