<?php

declare(strict_types=1);

use App\Support\PostNoteMentions;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('the composer reads mentions in a note body the way the server does', function () {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $ana = '0198d0aa-1b2c-7d3e-8f40-5a6b7c8d9e0f';
    $bia = '0198D0AA-1B2C-7D3E-8F40-5A6B7C8D9E1F';
    $corpus = [
        'No mention here',
        "@[Ana Lima](user:{$ana}) can you check?",
        "@[Ana](user:{$ana}) and @[Ana again](user:{$ana}) once",
        "Uppercase id @[Bia](user:{$bia})",
        'Not a uuid @[Caio](user:0198d0aa-1b2c-7d3e-8f40-zzzzzzzzzzzz)',
        "Broken @[Ana](user:{$ana}",
        "Line\n@[Bia Souza](user:{$bia}), and emoji 🎉 @[José](user:{$ana})",
        'Plain @Ana without markup',
    ];

    $file = tempnam(sys_get_temp_dir(), 'mentions');
    file_put_contents($file, json_encode($corpus, JSON_THROW_ON_ERROR));

    $process = (new Process([$node, base_path('tests/fixtures/note-mentions-harness.ts'), resource_path('js'), $file]))->mustRun();
    unlink($file);

    $server = array_map(fn (string $body): array => [
        'ids' => PostNoteMentions::extract($body),
        'plain' => PostNoteMentions::toPlainText($body),
    ], $corpus);

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($server);
});
