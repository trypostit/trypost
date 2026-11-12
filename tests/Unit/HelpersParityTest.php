<?php

declare(strict_types=1);

use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `resources/js/lib/helpers.ts` mirrors Laravel's helpers (`blank()`, `filled()`,
 * `transform()`, `Number::clamp()`, `isEmpty()` on a string or a collection, `Str::isUuid()`) and PHP's `is_string()` / `is_int()` /
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
    $empties = array_map(
        fn (string|array $value): bool => is_string($value) ? Str::of($value)->isEmpty() : collect($value)->isEmpty(),
        ['', ' ', 'a', '0', [], [null], ['']],
    );
    $clamped = array_map(
        fn (array $range): int|float => Number::clamp(...$range),
        [[5, 1, 10], [-3, 1, 10], [42, 1, 10], [1, 1, 10], [10, 1, 10], [2.5, 0, 1]],
    );

    $uuids = array_map(
        fn (mixed $value): bool => Str::isUuid($value),
        [
            '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0f',
            '0198D0AA-1B2C-7D3E-8F40-5A6B7C8D9E0F',
            '00000000-0000-0000-0000-000000000000',
            '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0',
            '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0fa',
            '0198d0aa1b2c7d3e8f405a6b7c8d9e0f',
            '0198d0aa-1b2c-7d3e-8f40-zzzzzzzzzzzz',
            '------------------------------------',
            ' 0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0f',
            "0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0f\n",
            '',
            null,
            42,
        ],
    );

    $labels = array_map(
        fn (int $index, mixed $value): string => "{$index}: ".json_encode($value, JSON_THROW_ON_ERROR),
        array_keys($corpus),
        $corpus,
    );

    expect(array_combine($labels, data_get($fromTypeScript, 'values')))->toEqual(array_combine($labels, $fromPhp))
        ->and(data_get($fromTypeScript, 'clamped'))->toEqual($clamped)
        ->and(data_get($fromTypeScript, 'empties'))->toEqual($empties)
        ->and(data_get($fromTypeScript, 'uuids'))->toEqual($uuids);
});
