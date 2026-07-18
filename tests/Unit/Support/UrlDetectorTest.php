<?php

declare(strict_types=1);

use App\Support\UrlDetector;

test('finds the first url in text', function () {
    expect(UrlDetector::firstUrl('read https://example.com/article now'))
        ->toBe('https://example.com/article');
});

test('returns null when there is no url', function () {
    expect(UrlDetector::firstUrl('just some #text and @handle'))->toBeNull();
});

test('trims trailing sentence punctuation', function () {
    expect(UrlDetector::firstUrl('see https://example.com.'))->toBe('https://example.com');
});

test('drops an unmatched closing paren but keeps a matched one', function () {
    expect(UrlDetector::firstUrl('see https://example.com)'))->toBe('https://example.com');
    expect(UrlDetector::firstUrl('see https://en.wikipedia.org/wiki/Foo_(bar)'))
        ->toBe('https://en.wikipedia.org/wiki/Foo_(bar)');
});

test('returns the first of several urls', function () {
    expect(UrlDetector::firstUrl('https://a.com and https://b.com'))->toBe('https://a.com');
});
