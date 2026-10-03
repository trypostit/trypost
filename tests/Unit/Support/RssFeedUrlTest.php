<?php

declare(strict_types=1);

use App\Support\RssFeedUrl;

test('url variants of one feed share an identity', function (string $url) {
    expect(RssFeedUrl::identity($url))->toBe('example.com/feed')
        ->and(RssFeedUrl::hash($url))->toBe(hash('sha256', 'example.com/feed'));
})->with([
    'http, uppercase host, trailing slash' => 'http://Example.COM/feed/',
    'https' => 'https://example.com/feed',
    'https default port' => 'https://example.com:443/feed',
    'http default port' => 'http://example.com:80/feed',
    'fragment' => 'https://example.com/feed#top',
    'userinfo' => 'https://user:pw@example.com/feed',
    'trailing dot host' => 'https://example.com./feed',
    'dot segments' => 'https://example.com/a/../feed',
]);

test('meaningful differences produce a distinct identity', function (string $url) {
    expect(RssFeedUrl::identity($url))->not->toBe('example.com/feed');
})->with([
    'query' => 'https://example.com/feed?format=rss',
    'path case' => 'https://example.com/Feed',
    'www' => 'https://www.example.com/feed',
    'custom port' => 'https://example.com:8443/feed',
]);

test('the query string is kept and a custom port stays in the identity', function () {
    expect(RssFeedUrl::identity('https://example.com/feed?format=rss'))->toBe('example.com/feed?format=rss')
        ->and(RssFeedUrl::identity('https://example.com:8443/feed'))->toBe('example.com:8443/feed');
});

test('an empty path and the root path are the root', function (string $url) {
    expect(RssFeedUrl::identity($url))->toBe('example.com/');
})->with([
    'https://example.com',
    'https://example.com/',
]);
