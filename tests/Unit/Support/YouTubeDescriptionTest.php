<?php

declare(strict_types=1);

use App\Support\YouTubeDescription;

test('youtube description validates text and byte limits', function (mixed $text, ?string $key) {
    expect(YouTubeDescription::violation($text))->toBe($key);
})->with([
    'absent' => [null, null],
    'empty' => ['', null],
    'ascii boundary' => [str_repeat('a', 5000), null],
    'ascii overflow' => [str_repeat('a', 5001), 'posts.form.youtube.description_max'],
    'accent boundary' => [str_repeat('é', 2500), null],
    'accent overflow' => [str_repeat('é', 2500).'a', 'posts.form.youtube.description_max'],
    'emoji boundary' => [str_repeat('😀', 1250), null],
    'emoji overflow' => [str_repeat('😀', 1250).'a', 'posts.form.youtube.description_max'],
    'multiline url' => ["Line one\n\nhttps://example.com\n#video", null],
    'opening bracket' => ['a < b', null],
    'closing bracket' => ['a > b', null],
    'literal markup' => ['<p>Text about HTML</p>', null],
    'invalid utf8' => ["\xC3\x28", 'posts.form.youtube.description_invalid'],
    'non string' => [['text'], 'posts.form.youtube.description_invalid'],
]);

test('youtube description resolves legacy and cleared metadata', function (mixed $description, string $expected) {
    expect(YouTubeDescription::resolve(['description' => $description], 'Title'))->toBe($expected);
})->with([
    'null description' => [null, 'Title'],
    'empty description' => ['', 'Title'],
    'blank description' => [" \n ", 'Title'],
    'non-breaking spaces' => ["\u{00A0}\u{00A0}", 'Title'],
    'invalid metadata type' => [['invalid'], 'Title'],
    'custom description' => ['Custom', 'Custom'],
    'surrounding whitespace' => ["  Custom\ntext  ", "  Custom\ntext  "],
]);

test('youtube description resolves absent metadata and content', function () {
    expect(YouTubeDescription::resolve(null, 'Title'))->toBe('Title')
        ->and(YouTubeDescription::resolve([], null))->toBe('');
});
