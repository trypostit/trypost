<?php

declare(strict_types=1);

use App\Enums\Analytics\DateRangePreset;

test('each fixed preset ends on the zone\'s today and covers its days', function (DateRangePreset $preset, int $days) {
    $today = now('America/Sao_Paulo')->startOfDay();

    expect($preset->dates('America/Sao_Paulo'))
        ->toBe(['start' => $today->subDays($days - 1)->toDateString(), 'end' => $today->toDateString()]);
})->with([
    '7d' => [DateRangePreset::SevenDays, 7],
    '30d' => [DateRangePreset::ThirtyDays, 30],
    '60d' => [DateRangePreset::SixtyDays, 60],
    '90d' => [DateRangePreset::NinetyDays, 90],
]);

test('the month so far starts on the first of the zone\'s month', function () {
    $today = now('Asia/Tokyo')->startOfDay();

    expect(DateRangePreset::MonthToDate->dates('Asia/Tokyo'))
        ->toBe(['start' => $today->startOfMonth()->toDateString(), 'end' => $today->toDateString()]);
});

test('a custom range keeps the days it is given', function () {
    $start = now('UTC')->subDays(20)->toDateString();
    $end = now('UTC')->subDays(3)->toDateString();

    expect(DateRangePreset::Custom->dates('UTC', $start, $end))->toBe(['start' => $start, 'end' => $end]);
});

test('the values stay the ones the pages send', function () {
    expect(collect(DateRangePreset::cases())->map->value->all())->toBe(['7d', '30d', '60d', '90d', 'mtd', 'custom'])
        ->and(collect(DateRangePreset::admin())->map->value->all())->toBe(['7d', '30d', '60d', '90d'])
        ->and(DateRangePreset::DEFAULT_RANGE)->toBe(DateRangePreset::ThirtyDays);
});
