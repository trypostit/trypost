<?php

declare(strict_types=1);

use Illuminate\Support\Number;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `resources/js/lib/helpers.ts` mirrors Laravel's helpers (`blank()`, `filled()`,
 * `transform()`, `Number::clamp()`) and PHP's `is_string()` / `is_int()` /
 * `is_float()`, so a check reads the same on the front end. Both sides answer
 * for the same corpus of JSON values (null, blank and filled strings, numbers
 * and booleans, empty and filled arrays and objects), and every answer must
 * match.
 */
test('the typescript helpers agree with laravel on every corpus entry', function () {
    $corpusPath = base_path('tests/fixtures/helpers-corpus.json');
    $corpus = json_decode(file_get_contents($corpusPath), true, flags: JSON_THROW_ON_ERROR);

    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('node is unavailable');
    }

    $process = (new Process([
        $node,
        base_path('tests/fixtures/helpers-harness.ts'),
        resource_path('js/lib/helpers.ts'),
        $corpusPath,
    ]))->mustRun();

    $fromPhp = array_map(fn (mixed $value): array => [
        'blank' => blank($value),
        'filled' => filled($value),
        'isString' => is_string($value),
        'isNumber' => is_int($value) || is_float($value),
        'transform' => transform($value, fn (): string => 'changed', 'fallback'),
    ], $corpus);

    $fromTypeScript = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $clamped = array_map(
        fn (array $range): int|float => Number::clamp(...$range),
        [[5, 1, 10], [-3, 1, 10], [42, 1, 10], [1, 1, 10], [10, 1, 10], [2.5, 0, 1]],
    );

    $labels = array_map(
        fn (int $index, mixed $value): string => "{$index}: ".json_encode($value, JSON_THROW_ON_ERROR),
        array_keys($corpus),
        $corpus,
    );

    expect(array_combine($labels, data_get($fromTypeScript, 'values')))->toEqual(array_combine($labels, $fromPhp))
        ->and(data_get($fromTypeScript, 'clamped'))->toEqual($clamped);
});
