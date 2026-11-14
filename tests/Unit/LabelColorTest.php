<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('a label chip reads in white on its color while white still contrasts, near black on a light one, and takes no color from anything else', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('node is unavailable');
    }

    $cases = [
        '#000000' => '#ffffff',
        '#16a34a' => '#ffffff',
        '#7c3aed' => '#ffffff',
        '#dc2626' => '#ffffff',
        '#808080' => '#ffffff',
        '#ffffff' => '#0a0a0a',
        '#d1d5db' => '#0a0a0a',
        '#facc15' => '#0a0a0a',
        '#F97316' => '#0a0a0a',
        '#FDFD96' => '#0a0a0a',
        'blue' => null,
        '#abc' => null,
        '#12345g' => null,
        ' #16a34a' => null,
        '' => null,
    ];

    $process = (new Process([
        $node,
        base_path('tests/fixtures/label-color-harness.ts'),
        resource_path('js/lib/labelColor.ts'),
        ...array_map('strval', array_keys($cases)),
    ]))->mustRun();

    expect(array_combine(array_map('strval', array_keys($cases)), json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)))
        ->toBe(array_combine(array_map('strval', array_keys($cases)), array_values($cases)));
});
