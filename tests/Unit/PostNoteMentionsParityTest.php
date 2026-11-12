<?php

declare(strict_types=1);

use App\Support\PostNoteMentions;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('the composer reads mentions in a note body the way the server does', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $corpusPath = base_path('tests/fixtures/note-mentions-corpus.json');
    $corpus = File::json($corpusPath, JSON_THROW_ON_ERROR);

    $process = (new Process([$node, base_path('tests/fixtures/note-mentions-harness.ts'), resource_path('js'), $corpusPath]))->mustRun();

    $server = collect($corpus)->map(fn (string $body): array => [
        'ids' => PostNoteMentions::extract($body)->all(),
        'plain' => PostNoteMentions::toPlainText($body),
    ])->all();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($server);
});
