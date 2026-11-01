<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs `tests/fixtures/share-card-harness.ts` over the share card's pure
 * arithmetic and returns its JSON output.
 *
 * @return array<string, mixed>
 */
function runShareCardHarness(): array
{
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $process = (new Process([$node, base_path('tests/fixtures/share-card-harness.ts'), resource_path('js')]))->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

test('the card picks the same value ticks as d3, which the page axes use', function () {
    expect(runShareCardHarness()['ticks'])->toEqual([
        'days' => [0, 5, 10, 15, 20, 25],
        'growth' => [-20, -10, 0, 10, 20, 30],
        'closeFollowers' => [9990, 9995, 10000, 10005, 10010],
        'fractions' => [0, 0.05, 0.1, 0.15, 0.2, 0.25, 0.3],
        'halfSteps' => [1000, 1000.5, 1001, 1001.5, 1002],
    ]);
});

test('the card dates the days the page axes date, on whole days only', function () {
    expect(runShareCardHarness()['dates'])->toEqual([
        'combo9' => [0, 2, 4, 6, 8],
        'combo19' => [0, 5, 10, 15],
        'even2' => [0, 1],
        'even30' => [0, 5, 10, 15, 20, 25],
    ]);
});

test('the stacked card labels every column that fits and skips evenly when they do not', function () {
    expect(runShareCardHarness()['columns'])->toEqual([
        'crowded' => [0, 3, 6, 9, 12],
        'roomy' => [0, 1, 2, 3],
    ]);
});

test('a value axis runs over the values with zero in it', function () {
    $axis = runShareCardHarness()['axis'];

    expect($axis['ticks'])->toEqual([-20, -10, 0, 10, 20, 30])
        ->and($axis['zero'])->toEqualWithDelta(26 / 56, 0.000001);
});

test('the card draws the same monotone curve as d3: flat before a spike, eased while rising, straight for two points', function () {
    expect(runShareCardHarness()['curve'])->toEqual([
        'spike' => [
            ['M', 0, 10],
            ['C', 3.333333, 10, 6.666667, 10, 10, 10],
            ['C', 13.333333, 10, 16.666667, 10, 20, 10],
            ['C', 23.333333, 10, 26.666667, 80, 30, 80],
            ['C', 33.333333, 80, 36.666667, 50, 40, 20],
        ],
        'rising' => [
            ['M', 0, 0],
            ['C', 3.333333, 0.166667, 6.666667, 0.333333, 10, 1],
            ['C', 13.333333, 1.666667, 16.666667, 5.166667, 20, 10],
            ['C', 23.333333, 14.833333, 26.666667, 22.416667, 30, 30],
        ],
        'two' => [['M', 0, 0], ['L', 10, 5]],
    ]);
});

test('line end labels move apart just enough and stay inside the plot', function () {
    expect(runShareCardHarness()['labels'])->toEqual([
        'apart' => [100, 124, 300],
        'atTheBottom' => [364, 388],
    ]);
});
