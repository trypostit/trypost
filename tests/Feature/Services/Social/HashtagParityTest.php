<?php

declare(strict_types=1);

use App\Support\Hashtags;
use Symfony\Component\Process\Process;

test('the typescript hashtag counter agrees with the php one on every corpus entry', function () {
    $corpusPath = base_path('tests/fixtures/hashtag-corpus.json');
    $corpus = json_decode(file_get_contents($corpusPath), true, flags: JSON_THROW_ON_ERROR);

    $process = new Process([
        'node',
        base_path('tests/fixtures/hashtag-harness.js'),
        resource_path('js/lib/hashtags.ts'),
        $corpusPath,
    ]);
    $process->run();

    if (! $process->isSuccessful()) {
        $this->markTestSkipped('node is unavailable: '.$process->getErrorOutput());
    }

    $fromPhp = array_map(fn (string $entry): int => Hashtags::count($entry), $corpus);
    $fromTypeScript = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_combine($corpus, $fromTypeScript))->toEqual(array_combine($corpus, $fromPhp));
});
