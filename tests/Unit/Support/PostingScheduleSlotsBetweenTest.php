<?php

declare(strict_types=1);

use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;

function slotsBetweenMondayWednesdaySchedule(): PostingSchedule
{
    return PostingSchedule::empty()->withTime(1, '09:00')->withTime(3, '09:00');
}

test('returns every slot inside the range in ascending UTC order', function () {
    $slots = slotsBetweenMondayWednesdaySchedule()->slotsBetween(
        CarbonImmutable::parse('2026-10-05 00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-18 23:59', 'UTC'),
        'America/Sao_Paulo',
    );

    expect(array_map(fn ($slot) => $slot->format('Y-m-d H:i'), $slots))->toBe([
        '2026-10-05 12:00',
        '2026-10-07 12:00',
        '2026-10-12 12:00',
        '2026-10-14 12:00',
    ]);
});

test('an until before after returns nothing', function () {
    $slots = slotsBetweenMondayWednesdaySchedule()->slotsBetween(
        CarbonImmutable::parse('2026-10-10 00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-05 00:00', 'UTC'),
        'UTC',
    );

    expect($slots)->toBe([]);
});

test('an empty schedule returns nothing', function () {
    $slots = PostingSchedule::empty()->slotsBetween(
        CarbonImmutable::parse('2026-10-05 00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-18 00:00', 'UTC'),
        'UTC',
    );

    expect($slots)->toBe([]);
});

test('a far future until stops at the 2037 ceiling', function () {
    $slots = PostingSchedule::empty()->withTime(1, '09:00')->slotsBetween(
        CarbonImmutable::parse('2026-10-05 00:00', 'UTC'),
        CarbonImmutable::parse('2040-01-01 00:00', 'UTC'),
        'UTC',
    );

    expect($slots)->not->toBeEmpty()
        ->and(end($slots)->lessThanOrEqualTo(CarbonImmutable::parse(PostingSchedule::MAX_INSTANT, 'UTC')))->toBeTrue();
});

test('a slot exactly at until is included and one exactly at after is excluded', function () {
    $slots = slotsBetweenMondayWednesdaySchedule()->slotsBetween(
        CarbonImmutable::parse('2026-10-05 09:00', 'UTC'),
        CarbonImmutable::parse('2026-10-07 09:00', 'UTC'),
        'UTC',
    );

    expect(array_map(fn ($slot) => $slot->format('Y-m-d H:i'), $slots))->toBe(['2026-10-07 09:00']);
});
