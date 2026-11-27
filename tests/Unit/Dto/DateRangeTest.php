<?php

declare(strict_types=1);

use App\Dto\Analytics\DateRange;
use Carbon\CarbonImmutable;

test('a range built from calendar days keeps those days in a zone ahead of UTC', function () {
    $today = CarbonImmutable::now('Asia/Tokyo')->startOfDay();
    $range = DateRange::fromDates($today->subDays(2)->toDateString(), $today->toDateString(), 'Asia/Tokyo');

    expect($range->calendarDays())->toBe([
        $today->subDays(2)->toDateString(),
        $today->subDay()->toDateString(),
        $today->toDateString(),
    ])
        ->and($range->startsAt()->equalTo($today->subDays(2)))->toBeTrue()
        ->and($range->endsAt()->equalTo($today->endOfDay()))->toBeTrue()
        ->and($range->previous()->calendarDays())->toHaveCount(3);
});
