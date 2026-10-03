<?php

declare(strict_types=1);

use App\Support\PostingSchedule;
use Carbon\CarbonImmutable;

test('slots come after the given instant, skip disabled days and are ordered', function () {
    $schedule = PostingSchedule::empty()
        ->withTime(1, '09:00')
        ->withTime(1, '18:00')
        ->withTime(3, '12:00')
        ->withDayEnabled(3, false)
        ->withTime(5, '08:30');

    $after = CarbonImmutable::parse('2026-10-05 10:00', 'America/Sao_Paulo');

    $slots = $schedule->nextSlots($after, 'America/Sao_Paulo', 4);

    expect(array_map(fn ($slot) => $slot->setTimezone('America/Sao_Paulo')->format('D Y-m-d H:i'), $slots))
        ->toBe(['Mon 2026-10-05 18:00', 'Fri 2026-10-09 08:30', 'Mon 2026-10-12 09:00', 'Mon 2026-10-12 18:00'])
        ->and($slots[0]->getTimezone()->getName())->toBe('UTC');
});

test('half-hour offsets are respected', function () {
    $schedule = PostingSchedule::empty()->withTime(2, '09:00');

    $slot = $schedule->nextSlots(CarbonImmutable::parse('2026-10-05 00:00', 'UTC'), 'Asia/Kolkata', 1)[0];

    expect($slot->format('Y-m-d H:i'))->toBe('2026-10-06 03:30');
});

test('a nonexistent local time on a spring-forward day moves forward', function () {
    $schedule = PostingSchedule::empty()->withTime(0, '02:30');

    $slot = $schedule->nextSlots(CarbonImmutable::parse('2026-03-28 12:00', 'Europe/Warsaw'), 'Europe/Warsaw', 1)[0];

    expect($slot->setTimezone('Europe/Warsaw')->format('Y-m-d H:i'))->toBe('2026-03-29 03:30');
});

test('an ambiguous local time on a fall-back day uses the first occurrence', function () {
    $schedule = PostingSchedule::empty()->withTime(0, '02:30');

    $slot = $schedule->nextSlots(CarbonImmutable::parse('2026-10-24 12:00', 'Europe/Warsaw'), 'Europe/Warsaw', 1)[0];

    expect($slot->format('Y-m-d H:i'))->toBe('2026-10-25 00:30');
});

test('an empty or all-off schedule yields no slots', function () {
    $after = CarbonImmutable::now();

    expect(PostingSchedule::empty()->nextSlots($after, 'UTC', 3))->toBe([])
        ->and(PostingSchedule::empty()->withTime(1, '09:00')->withDayEnabled(1, false)->nextSlots($after, 'UTC', 3))->toBe([]);
});

test('slots never go past the 2037 ceiling', function () {
    $schedule = PostingSchedule::empty()->withTime(4, '10:00');

    $slots = $schedule->nextSlots(CarbonImmutable::parse('2037-12-20 00:00', 'UTC'), 'UTC', 10);

    expect($slots)->toHaveCount(2)
        ->and(end($slots)->format('Y-m-d'))->toBe('2037-12-31');
});

test('a time shifted by a spring-forward gap stays ordered with the times after it', function () {
    $schedule = PostingSchedule::empty()->withTime(0, '02:30')->withTime(0, '03:15');

    $slots = $schedule->nextSlots(CarbonImmutable::parse('2026-03-07 12:00', 'America/New_York'), 'America/New_York', 2);

    expect(array_map(fn ($slot) => $slot->setTimezone('America/New_York')->format('Y-m-d H:i'), $slots))
        ->toBe(['2026-03-08 03:15', '2026-03-08 03:30']);
});

test('a time shifted by a spring-forward gap onto another time yields a single slot', function () {
    $schedule = PostingSchedule::empty()->withTime(0, '02:30')->withTime(0, '03:30');

    $slots = $schedule->nextSlots(CarbonImmutable::parse('2026-03-07 12:00', 'America/New_York'), 'America/New_York', 2);

    expect(array_map(fn ($slot) => $slot->setTimezone('America/New_York')->format('Y-m-d H:i'), $slots))
        ->toBe(['2026-03-08 03:30', '2026-03-15 02:30']);
});
