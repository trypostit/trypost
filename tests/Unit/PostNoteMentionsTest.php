<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\PostNoteMentions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The server is the one place that reads mention markup: it splits a note into
 * parts for the app, and the note editor (TipTap) writes the markup back.
 */
test('a note splits into its text, its people and everyone, in order and with nothing lost', function () {
    $corpus = File::json(base_path('tests/fixtures/note-mentions-corpus.json'), JSON_THROW_ON_ERROR);

    foreach ($corpus as $body) {
        $parts = PostNoteMentions::parts($body, collect());
        $rebuilt = collect($parts)->map(fn (array $part): string => match (data_get($part, 'type')) {
            'text' => data_get($part, 'text'),
            'mention' => "@[user:{$part['id']}]",
            'everyone' => '@[everyone]',
        })->implode('');

        expect(Str::lower($rebuilt))->toBe(Str::lower($body))
            ->and(collect($parts)->where('type', 'mention')->pluck('id')->unique()->values()->all())->toBe(PostNoteMentions::extract($body)->all())
            ->and(collect($parts)->contains('type', 'everyone'))->toBe(PostNoteMentions::mentionsEveryone($body));
    }
});

test('a mention part carries the name of the person it names, or null once they are gone', function () {
    $people = collect([User::factory()->make(['name' => 'Bia Lima'])->forceFill(['id' => '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0f'])]);

    expect(PostNoteMentions::parts('Ask @[user:0198D0AA-1B2C-7D3E-8F40-5A6B7C8D9E0F] and @[user:0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e1f]', $people))->toBe([
        ['type' => 'text', 'text' => 'Ask '],
        ['type' => 'mention', 'id' => '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0f', 'name' => 'Bia Lima'],
        ['type' => 'text', 'text' => ' and '],
        ['type' => 'mention', 'id' => '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e1f', 'name' => null],
    ]);
});

test('a note opened in the editor from its parts and stored again is unchanged', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $bodies = File::json(base_path('tests/fixtures/note-mentions-round-trip.json'), JSON_THROW_ON_ERROR);
    $partsFile = tempnam(sys_get_temp_dir(), 'note-parts');
    File::put($partsFile, json_encode(array_map(fn (string $body): array => PostNoteMentions::parts($body, collect()), $bodies), JSON_THROW_ON_ERROR));

    $process = (new Process([$node, base_path('tests/fixtures/note-mentions-harness.ts'), resource_path('js'), $partsFile]))->mustRun();
    File::delete($partsFile);

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($bodies);
});
