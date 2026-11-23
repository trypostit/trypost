<?php

declare(strict_types=1);

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('a toast link goes where it says, and a toast without one drops the link of the toast it replaces', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('node is unavailable');
    }

    $process = (new Process([$node, base_path('tests/fixtures/toast-link-harness.ts'), resource_path('js')]))->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'label' => 'View post',
        'visited' => ['/posts?post=1'],
        'widened' => true,
        'keysWithoutLink' => ['action', 'classes'],
        'mergedKeepsLink' => false,
    ]);
});
