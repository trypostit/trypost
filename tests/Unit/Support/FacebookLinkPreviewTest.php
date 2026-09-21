<?php

declare(strict_types=1);

use App\Support\FacebookLinkPreview;
use Symfony\Component\Process\Process;

/**
 * Captions the publisher and the editor must resolve to the same `link`.
 * A null value means the post goes out as plain text.
 *
 * @return array<string, ?string>
 */
function facebookLinkPreviewCorpus(): array
{
    return [
        'no link here' => null,
        'Read https://example.com/post today' => 'https://example.com/post',
        'See https://example.com/post.' => 'https://example.com/post',
        'See https://example.com/post).' => 'https://example.com/post',
        'See https://www.facebook.com/page' => null,
        'See https://m.facebook.com/page' => null,
        'See https://facebook.com/events/1' => null,
        'See https://FB.com/page' => null,
        'See https://fb.me/abc' => null,
        'See https://l.facebook.com/l.php?u=https://example.com' => null,
        'See https://www.facebook.com/page and https://example.com/post.' => 'https://example.com/post',
        'See https://notfacebook.com/post' => 'https://notfacebook.com/post',
        'See https://example.com/a..' => 'https://example.com/a.',
    ];
}

test('facebook link preview picks the url the page feed will accept', function () {
    foreach (facebookLinkPreviewCorpus() as $text => $expected) {
        expect(FacebookLinkPreview::url($text))->toBe($expected);
    }
});

test('the editor picks the same facebook link as the publisher', function () {
    $corpus = array_keys(facebookLinkPreviewCorpus());
    $corpusPath = tempnam(sys_get_temp_dir(), 'fb_links').'.json';
    file_put_contents($corpusPath, json_encode($corpus, JSON_THROW_ON_ERROR));

    $process = new Process([
        'node',
        '--experimental-strip-types',
        base_path('tests/fixtures/facebook-link-preview-harness.js'),
        resource_path('js/lib/facebookLinkPreview.ts'),
        $corpusPath,
    ]);
    $process->run();

    unlink($corpusPath);

    if (! $process->isSuccessful()) {
        $this->markTestSkipped('node is unavailable: '.$process->getErrorOutput());
    }

    $fromTypeScript = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $fromPhp = array_map(fn (string $entry): ?string => FacebookLinkPreview::url($entry), $corpus);

    expect(array_combine($corpus, $fromTypeScript))->toEqual(array_combine($corpus, $fromPhp));
});
