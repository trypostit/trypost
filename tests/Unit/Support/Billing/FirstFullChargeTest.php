<?php

declare(strict_types=1);

use App\Enums\Billing\Interval;
use App\Support\Billing\FirstFullCharge;
use Carbon\CarbonImmutable;

test('first renewal after a $1 month is the first full charge', function () {
    expect(FirstFullCharge::isFirst(
        CarbonImmutable::parse('2026-10-01'),
        CarbonImmutable::parse('2026-10-31'),
        Interval::Monthly,
    ))->toBeTrue();
});

test('second renewal is not', function () {
    expect(FirstFullCharge::isFirst(
        CarbonImmutable::parse('2026-09-01'),
        CarbonImmutable::parse('2026-10-31'),
        Interval::Monthly,
    ))->toBeFalse();
});

test('trial end is the first full charge', function () {
    expect(FirstFullCharge::isFirst(
        CarbonImmutable::parse('2026-10-01'),
        CarbonImmutable::parse('2026-10-09'),
        Interval::Monthly,
    ))->toBeTrue();
});

test('yearly first renewal', function () {
    expect(FirstFullCharge::isFirst(
        CarbonImmutable::parse('2025-10-01'),
        CarbonImmutable::parse('2026-10-01'),
        Interval::Yearly,
    ))->toBeTrue();
});

test('yearly second renewal is not', function () {
    expect(FirstFullCharge::isFirst(
        CarbonImmutable::parse('2024-10-01'),
        CarbonImmutable::parse('2026-10-01'),
        Interval::Yearly,
    ))->toBeFalse();
});
