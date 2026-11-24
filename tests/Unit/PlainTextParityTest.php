<?php

declare(strict_types=1);

use App\Services\Social\ContentSanitizer;
use Symfony\Component\Process\Process;

test('the composer reads plain text the way the server sends it', function () {
    $corpusPath = base_path('tests/fixtures/plain-text-corpus.json');
    $corpus = json_decode(file_get_contents($corpusPath), true, flags: JSON_THROW_ON_ERROR);

    $process = new Process(['node', base_path('tests/fixtures/plain-text-harness.ts'), resource_path('js'), $corpusPath]);
    $process->run();

    if (! $process->isSuccessful()) {
        $this->markTestSkipped("node is unavailable: {$process->getErrorOutput()}");
    }

    $sanitizer = app(ContentSanitizer::class);
    $fromPhp = array_combine($corpus, array_map($sanitizer->plainText(...), $corpus));
    $fromTypeScript = array_combine($corpus, json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));

    expect($fromTypeScript)->toBe($fromPhp);
});
