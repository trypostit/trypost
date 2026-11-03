<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * `resources/js/lib/helpers.ts` mirrors Laravel's `blank()` and `filled()`, so a
 * check reads the same on the front end. Both sides answer for the same corpus
 * of JSON values (null, blank and filled strings, numbers and booleans, empty
 * and filled arrays and objects), and every answer must match.
 */
test('the typescript blank and filled agree with laravel on every corpus entry', function () {
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
    ], $corpus);

    $fromTypeScript = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    $labels = array_map(
        fn (int $index, mixed $value): string => "{$index}: ".json_encode($value, JSON_THROW_ON_ERROR),
        array_keys($corpus),
        $corpus,
    );

    expect(array_combine($labels, $fromTypeScript))->toEqual(array_combine($labels, $fromPhp));
});
