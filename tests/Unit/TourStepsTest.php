<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('a tour walks its steps in its own order, skipping the ones not on the page', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('node is unavailable');
    }

    $cases = [
        ['steps' => ['one', 'two', 'three'], 'onPage' => ['three', 'one', 'two'], 'current' => 'one'],
        ['steps' => ['one', 'two', 'three'], 'onPage' => ['one', 'two', 'three'], 'current' => 'two'],
        ['steps' => ['one', 'two', 'three'], 'onPage' => ['one', 'two', 'three'], 'current' => 'three'],
        ['steps' => ['one', 'two', 'three'], 'onPage' => ['one', 'three'], 'current' => 'one'],
        ['steps' => ['one'], 'onPage' => ['one'], 'current' => 'one'],
    ];

    $process = (new Process([
        $node,
        base_path('tests/fixtures/tours-harness.ts'),
        resource_path('js/lib/tours.ts'),
        json_encode($cases),
    ]))->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        ['shown' => ['one', 'two', 'three'], 'after' => 'two', 'before' => null],
        ['shown' => ['one', 'two', 'three'], 'after' => 'three', 'before' => 'one'],
        ['shown' => ['one', 'two', 'three'], 'after' => null, 'before' => 'two'],
        ['shown' => ['one', 'three'], 'after' => 'three', 'before' => null],
        ['shown' => ['one'], 'after' => null, 'before' => null],
    ]);
});
