<?php

declare(strict_types=1);

use App\Support\YouTubeDescription;

test('youtube description limit messages omit encoding details', function () {
    foreach (glob(dirname(__DIR__, 3).'/lang/*/posts.php') as $path) {
        $translations = require $path;

        expect($translations['form']['youtube']['description_max'])->not->toContain('UTF-8');
    }
});

test('youtube description validates utf8 bytes and forbidden characters', function (mixed $text, ?string $key) {
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
    'opening bracket' => ['a < b', 'posts.form.youtube.description_invalid'],
    'closing bracket' => ['a > b', 'posts.form.youtube.description_invalid'],
    'invalid utf8' => ["\xC3\x28", 'posts.form.youtube.description_invalid'],
    'non string' => [['text'], 'posts.form.youtube.description_invalid'],
]);

test('youtube description resolves legacy and cleared metadata', function (mixed $description, string $expected) {
    expect(YouTubeDescription::resolve(['description' => $description], 'Title'))->toBe($expected);
})->with([
    [null, 'Title'], ['', 'Title'], [" \n ", 'Title'], [['invalid'], 'Title'], ['Custom', 'Custom'],
]);

test('youtube description resolves absent metadata and content', function () {
    expect(YouTubeDescription::resolve(null, 'Title'))->toBe('Title')
        ->and(YouTubeDescription::resolve([], null))->toBe('');
});
