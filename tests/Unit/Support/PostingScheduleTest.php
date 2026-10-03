<?php

declare(strict_types=1);

use App\Support\PostingSchedule;

test('empty has seven enabled days without times', function () {
    $days = PostingSchedule::empty()->days();

    expect($days)->toHaveCount(7)
        ->and(array_column($days, 'day'))->toBe([0, 1, 2, 3, 4, 5, 6])
        ->and(array_unique(array_column($days, 'enabled')))->toBe([true])
        ->and(array_merge(...array_column($days, 'times')))->toBe([]);
});

test('fromArray sorts and dedupes times and orders days', function () {
    $input = array_reverse(PostingSchedule::empty()->toArray());
    $input[5]['times'] = ['18:00', '09:30', '18:00'];

    $schedule = PostingSchedule::fromArray($input);

    expect(array_column($schedule->days(), 'day'))->toBe([0, 1, 2, 3, 4, 5, 6])
        ->and($schedule->days()[1]['times'])->toBe(['09:30', '18:00']);
});

test('fromArray rejects malformed input', function (array $input) {
    PostingSchedule::fromArray($input);
})->throws(InvalidArgumentException::class)->with([
    'six days' => [array_slice(PostingSchedule::empty()->toArray(), 0, 6)],
    'bad time' => [array_replace(PostingSchedule::empty()->toArray(), [2 => ['day' => 2, 'enabled' => true, 'times' => ['25:00']]])],
    'duplicate day' => [array_replace(PostingSchedule::empty()->toArray(), [3 => ['day' => 2, 'enabled' => true, 'times' => []]])],
    'too many times' => [array_replace(PostingSchedule::empty()->toArray(), [1 => ['day' => 1, 'enabled' => true, 'times' => ['01:00', '02:00', '03:00', '04:00', '05:00']]])],
]);

test('withTime adds sorted, ignores duplicates and enforces the daily limit', function () {
    $schedule = PostingSchedule::empty()
        ->withTime(1, '18:00')
        ->withTime(1, '09:00')
        ->withTime(1, '09:00');

    expect($schedule->days()[1]['times'])->toBe(['09:00', '18:00']);

    $full = $schedule->withTime(1, '10:00')->withTime(1, '11:00');

    expect(fn () => $full->withTime(1, '12:00'))->toThrow(InvalidArgumentException::class);
});

test('withoutTime, withDayEnabled, cleared and slotCount', function () {
    $schedule = PostingSchedule::empty()
        ->withTime(0, '10:00')
        ->withTime(1, '09:00')
        ->withTime(1, '18:00');

    expect($schedule->slotCount())->toBe(3)
        ->and($schedule->withoutTime(1, '09:00')->days()[1]['times'])->toBe(['18:00'])
        ->and($schedule->withDayEnabled(1, false)->slotCount())->toBe(1)
        ->and($schedule->withDayEnabled(1, false)->days()[1]['times'])->toBe(['09:00', '18:00'])
        ->and($schedule->cleared()->slotCount())->toBe(0)
        ->and($schedule->withDayEnabled(1, false)->cleared()->days()[1]['enabled'])->toBeFalse();
});

test('operations never mutate the original', function () {
    $original = PostingSchedule::empty();
    $original->withTime(2, '08:00');

    expect($original->slotCount())->toBe(0);
});
