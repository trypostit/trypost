<?php

declare(strict_types=1);

use App\Support\Analytics\Pdf\SvgChart;

test('bars grow below the zero line for a loss', function () {
    $svg = SvgChart::timeSeries([5, -3, 0], ['1 Oct', '2 Oct', '3 Oct'], 'bar', '#7c3aed', fn (int|float $value): string => (string) $value);

    expect(substr_count($svg, '<rect '))->toBe(2)
        ->and($svg)->toContain('>-5<')
        ->and($svg)->toContain('>5<');
});

test('a line skips the days without a value instead of dropping to zero', function () {
    $svg = SvgChart::timeSeries([100, null, 102, 103], ['a', 'b', 'c', 'd'], 'line', '#eb6834', fn (int|float $value): string => (string) $value);

    expect(substr_count($svg, '<circle '))->toBe(1)
        ->and(substr_count($svg, '<polyline '))->toBe(1)
        ->and($svg)->not->toContain('>0<');
});

test('labels are escaped', function () {
    $svg = SvgChart::timeSeries([1], ['<b>Oct</b>'], 'bar', '#7c3aed', fn (int|float $value): string => (string) $value);

    expect($svg)->toContain('&lt;b&gt;Oct&lt;/b&gt;')
        ->and($svg)->not->toContain('<b>');
});

test('a line of small counts gets whole-number ticks, never repeated ones', function () {
    $svg = SvgChart::timeSeries([0, 0, 1, 1], ['a', 'b', 'c', 'd'], 'line', '#7c3aed', fn (int|float $value): string => (string) $value);

    preg_match_all('/text-anchor="end"[^>]*>([^<]+)</', $svg, $ticks);

    expect($ticks[1])->toBe(array_values(array_unique($ticks[1])))
        ->and(array_filter($ticks[1], fn (string $tick): bool => str_contains($tick, '.')))->toBe([]);
});
