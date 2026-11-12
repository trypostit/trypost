<?php

declare(strict_types=1);

use App\Support\PostNoteMentions;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs `tests/fixtures/note-mentions-harness.ts` over a corpus file and decodes
 * what it prints.
 *
 * @return list<mixed>
 */
function noteMentionsHarness(string $corpusPath, string $mode = 'read'): array
{
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $process = (new Process([$node, base_path('tests/fixtures/note-mentions-harness.ts'), resource_path('js'), $corpusPath, $mode]))->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

test('the composer reads mentions in a note body the way the server does', function () {
    app()->setLocale('en');
    $corpusPath = base_path('tests/fixtures/note-mentions-corpus.json');

    $server = collect(File::json($corpusPath, JSON_THROW_ON_ERROR))->map(fn (string $body): array => [
        'ids' => PostNoteMentions::extract($body)->all(),
        'everyone' => PostNoteMentions::mentionsEveryone($body),
        'plain' => PostNoteMentions::toPlainText($body),
    ])->all();

    expect(noteMentionsHarness($corpusPath))->toBe($server);
});

test('a body shown as @Name in the composer and stored again keeps every mention', function () {
    $corpusPath = base_path('tests/fixtures/note-mentions-round-trip.json');

    expect(noteMentionsHarness($corpusPath, 'round-trip'))->toBe(File::json($corpusPath, JSON_THROW_ON_ERROR));
});
