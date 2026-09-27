<?php

declare(strict_types=1);

use App\Support\YouTubeDescription;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

test('youtube description browser validation matches the backend byte contract', function () {
    if ((new ExecutableFinder)->find('node') === null) {
        $this->markTestSkipped('Node is unavailable.');
    }

    $corpus = [
        null,
        '',
        " \t\n\r\0\v",
        "\u{00A0}",
        "\u{2003}",
        "\f",
        'Title',
        "Before 😀 after \u{10000}\u{10FFFF}",
        '  Keep surrounding spaces  ',
        str_repeat('a', 5000),
        str_repeat('a', 5001),
        str_repeat('é', 2500),
        str_repeat('é', 2500).'a',
        str_repeat('😀', 1250),
        str_repeat('😀', 1250).'a',
        "Line one\nhttps://example.com",
        'a < b',
        'a > b',
        ['invalid'],
        42,
    ];

    $script = <<<'JS'
        import fs from 'node:fs';
        import module from 'node:module';
        if (typeof module.stripTypeScriptTypes !== 'function') process.exit(42);
        const source = fs.readFileSync(process.argv[1], 'utf8');
        const js = module.stripTypeScriptTypes(source);
        const {getYouTubeDescriptionIssue, normalizeYouTubeDescription, youtubeDescriptionBytes} = await import('data:text/javascript;base64,' + Buffer.from(js).toString('base64'));
        const corpus = JSON.parse(fs.readFileSync(0, 'utf8'));
        console.log(JSON.stringify({
            results: corpus.map(getYouTubeDescriptionIssue),
            resolved: corpus.map(value => normalizeYouTubeDescription(value) ?? 'Title'),
            bytes: youtubeDescriptionBytes('ação😀'),
            invalidUnicode: [
                '\uD800',
                '\uDC00',
                '\uD800a',
                'a\uDC00',
                '\uDBFF',
                '\uDFFF',
                '\uD800😀',
                '😀\uDC00',
                '\uDC00\uD800',
            ].map(getYouTubeDescriptionIssue),
        }));
    JS;
    $process = new Process(['node', '--input-type=module', '-e', $script, resource_path('js/lib/youtubeDescription.ts')], base_path());
    $process->setInput(json_encode($corpus, JSON_THROW_ON_ERROR));
    $process->run();

    if ($process->getExitCode() === 42) {
        $this->markTestSkipped('Node 22.13 or newer is required for native TypeScript support.');
    }

    expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['results'])->toEqual(array_map(YouTubeDescription::violation(...), $corpus))
        ->and($result['bytes'])->toBe(strlen('ação😀'))
        ->and($result['resolved'])->toEqual(array_map(
            fn (mixed $description): string => YouTubeDescription::resolve(['description' => $description], 'Title'),
            $corpus,
        ))
        ->and($result['invalidUnicode'])->toEqual(array_fill(0, 9, 'posts.form.youtube.description_invalid'));
});
