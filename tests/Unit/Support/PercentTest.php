<?php

declare(strict_types=1);

use App\Support\Percent;

test('a part of a whole is its share in percent, rounded to one decimal by default', function () {
    expect(Percent::of(127, 780))->toBe(16.3)
        ->and(Percent::of(1, 3, 2))->toBe(33.33)
        ->and(Percent::of(5, 5))->toBe(100.0);
});

test('an empty whole gives 0, never a division by zero', function () {
    expect(Percent::of(0, 0))->toBe(0.0)
        ->and(Percent::of(3, 0))->toBe(0.0);
});

test('a change from a past value is signed, and null when there is nothing to compare with', function () {
    expect(Percent::change(780, 285))->toBe(173.7)
        ->and(Percent::change(16.3, 22.8))->toBe(-28.5)
        ->and(Percent::change(10, 10))->toBe(0.0)
        ->and(Percent::change(5, 0))->toBeNull();
});
