<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Services\Social\ContentSanitizer;
use App\Support\LinkTlds;
use Symfony\Component\Process\Process;

/**
 * The editor mirrors `ContentSanitizer`'s X branch in TypeScript so it can count
 * characters and preview without a round trip. PCRE and the JavaScript engine are
 * not the same, so agreement is asserted rather than assumed: both run the same
 * corpus, over the same TLD set, and every result must match.
 */
test('the typescript defuser agrees with the php one on every corpus entry', function () {
    $corpusPath = base_path('tests/fixtures/x-link-corpus.json');
    $corpus = json_decode(file_get_contents($corpusPath), true, flags: JSON_THROW_ON_ERROR);

    $tldsPath = tempnam(sys_get_temp_dir(), 'tlds').'.json';
    file_put_contents($tldsPath, json_encode(LinkTlds::all(), JSON_THROW_ON_ERROR));

    $process = new Process([
        'node',
        base_path('tests/fixtures/defuse-x-links-harness.js'),
        $tldsPath,
        resource_path('js/lib/defuseXLinks.ts'),
        $corpusPath,
    ]);
    $process->run();

    if (! $process->isSuccessful()) {
        $this->markTestSkipped('node is unavailable: '.$process->getErrorOutput());
    }

    config()->set('trypost.platforms.x.defuse_links', true);
    $sanitizer = new ContentSanitizer;

    $fromPhp = array_map(fn (string $entry): string => $sanitizer->sanitize($entry, Platform::X), $corpus);
    $fromTypeScript = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect(array_combine($corpus, $fromTypeScript))->toEqual(array_combine($corpus, $fromPhp));

    unlink($tldsPath);
});
