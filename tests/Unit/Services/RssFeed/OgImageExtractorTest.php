<?php

declare(strict_types=1);

use App\Services\RssFeed\OgImageExtractor;

function ogImageExtractorTestFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 3)."/fixtures/feeds/{$name}");
}

test('reads og:image before twitter:image and decodes entities', function () {
    expect(app(OgImageExtractor::class)->extract(ogImageExtractorTestFixture('page_with_og_image.html'), 'https://news.example.com/a'))
        ->toBe('https://cdn.example.com/og.jpg?w=1200&h=630');
});

test('falls back to twitter:image', function () {
    expect(app(OgImageExtractor::class)->extract(ogImageExtractorTestFixture('page_with_twitter_image.html'), 'https://news.example.com/a'))
        ->toBe('https://cdn.example.com/twitter.jpg');
});

test('resolves a relative image against the page url', function (string $html, string $pageUrl, string $expected) {
    expect(app(OgImageExtractor::class)->extract($html, $pageUrl))->toBe($expected);
})->with([
    'root relative fixture' => [ogImageExtractorTestFixture('page_relative_og_image.html'), 'https://news.example.com/posts/a', 'https://news.example.com/img/a.jpg'],
    'parent relative' => ['<meta property="og:image" content="../a.jpg">', 'https://news.example.com/posts/2026/a', 'https://news.example.com/posts/a.jpg'],
]);

test('drops insecure and unusable images', function (string $html) {
    expect(app(OgImageExtractor::class)->extract($html, 'https://news.example.com/a'))->toBeNull();
})->with([
    'http' => '<meta property="og:image" content="http://cdn.example.com/a.jpg">',
    'data uri' => '<meta property="og:image" content="data:image/png;base64,AAAA">',
    'too long' => '<meta property="og:image" content="https://cdn.example.com/'.str_repeat('a', 2100).'.jpg">',
]);

test('returns null for pages without og tags and for broken html', function (string $html) {
    expect(app(OgImageExtractor::class)->extract($html, 'https://news.example.com/a'))->toBeNull();
})->with([
    'no tags' => ogImageExtractorTestFixture('page_without_og_tags.html'),
    'empty' => '',
    'malformed' => '<html><head><meta property="og:image" <<<<>>> <title>',
    'binary' => "\x00\x01\x02\xff\xfe",
]);

test('falls back to twitter:image when og:image is unusable', function () {
    $html = '<meta property="og:image" content="http://cdn.example.com/a.jpg"><meta name="twitter:image" content="https://cdn.example.com/b.jpg">';

    expect(app(OgImageExtractor::class)->extract($html, 'https://news.example.com/a'))->toBe('https://cdn.example.com/b.jpg');
});
