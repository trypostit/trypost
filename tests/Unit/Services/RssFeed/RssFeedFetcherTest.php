<?php

declare(strict_types=1);

use App\Enums\RssFeed\Format;
use App\Exceptions\RssFeed\RssFeedFetchException;
use App\Services\RssFeed\RssFeedFetcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

function rssFeedFetcherTestFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/fixtures/feeds/{$name}"));
}

function rssFeedFetcherTestErrorKey(string $url, bool $allowDiscovery = false): ?string
{
    try {
        app(RssFeedFetcher::class)->fetch($url, $allowDiscovery);
    } catch (RssFeedFetchException $exception) {
        return $exception->errorKey;
    }

    return null;
}

beforeEach(function () {
    Http::preventStrayRequests();
});

test('refuses private and metadata hosts before any request', function (string $url) {
    Http::fake();

    expect(rssFeedFetcherTestErrorKey($url))->toBe('create.feeds.errors.blocked_url');

    Http::assertNothingSent();
})->with([
    'http://127.0.0.1/feed',
    'http://10.0.0.5/feed',
    'http://169.254.169.254/latest',
]);

test('a redirect to a private host is unreachable and never followed', function () {
    Http::fake([
        'http://93.184.216.34/feed.xml' => Http::response('', 302, ['Location' => 'http://127.0.0.1/']),
        'http://127.0.0.1/*' => Http::response(rssFeedFetcherTestFixture('announcekit_rss.xml')),
    ]);

    expect(rssFeedFetcherTestErrorKey('http://93.184.216.34/feed.xml'))->toBe('create.feeds.errors.unreachable');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

test('a body over the size cap is unreachable', function () {
    config()->set('trypost.rss_feeds.max_response_bytes', 1024);

    Http::fake([
        'http://93.184.216.34/feed.xml' => Http::response(str_repeat('a', 2048)),
    ]);

    expect(rssFeedFetcherTestErrorKey('http://93.184.216.34/feed.xml'))->toBe('create.feeds.errors.unreachable');
});

test('a failing response is unreachable', function () {
    Http::fake([
        'http://93.184.216.34/feed.xml' => Http::response('', 500),
    ]);

    expect(rssFeedFetcherTestErrorKey('http://93.184.216.34/feed.xml'))->toBe('create.feeds.errors.unreachable');
});

test('returns the final url after a redirect', function () {
    Http::fake([
        'http://93.184.216.34/feed.xml' => Http::response('', 301, ['Location' => 'https://1.1.1.1/rss.xml']),
        'https://1.1.1.1/rss.xml' => Http::response(rssFeedFetcherTestFixture('announcekit_rss.xml'), 200, ['Content-Type' => 'application/rss+xml']),
    ]);

    $fetched = app(RssFeedFetcher::class)->fetch('http://93.184.216.34/feed.xml');

    expect($fetched->url)->toBe('https://1.1.1.1/rss.xml')
        ->and($fetched->feed->format)->toBe(Format::Rss)
        ->and($fetched->feed->items)->toHaveCount(3);
});

test('parses a JSON Feed served as html', function () {
    Http::fake([
        'http://93.184.216.34/feed.json' => Http::response(rssFeedFetcherTestFixture('announcekit_jsonfeed.json'), 200, ['Content-Type' => 'text/html']),
    ]);

    expect(app(RssFeedFetcher::class)->fetch('http://93.184.216.34/feed.json')->feed->format)->toBe(Format::JsonFeed);
});

test('discovers the feed of an html page when allowed', function () {
    Http::fake([
        'http://93.184.216.34/' => Http::response(rssFeedFetcherTestFixture('html_with_alternate.html'), 200, ['Content-Type' => 'text/html']),
        'http://93.184.216.34/feed.xml' => Http::response('', 301, ['Location' => 'https://93.184.216.34/feed.xml']),
        'https://93.184.216.34/feed.xml' => Http::response(rssFeedFetcherTestFixture('announcekit_rss.xml')),
    ]);

    $fetched = app(RssFeedFetcher::class)->fetch('http://93.184.216.34/', allowDiscovery: true);

    expect($fetched->url)->toBe('https://93.184.216.34/feed.xml')
        ->and($fetched->feed->title)->toBe('AnnounceKit Product Updates');
});

test('an html page is not a feed without discovery', function () {
    Http::fake([
        'http://93.184.216.34/' => Http::response(rssFeedFetcherTestFixture('html_with_alternate.html')),
    ]);

    expect(rssFeedFetcherTestErrorKey('http://93.184.216.34/'))->toBe('create.feeds.errors.not_a_feed');

    Http::assertSentCount(1);
});

test('an html page without a feed link is not a feed even with discovery', function () {
    Http::fake([
        'http://93.184.216.34/' => Http::response(rssFeedFetcherTestFixture('not_a_feed.html')),
    ]);

    expect(rssFeedFetcherTestErrorKey('http://93.184.216.34/', allowDiscovery: true))->toBe('create.feeds.errors.not_a_feed');
});

test('a discovered link that is not a feed either stops after one hop', function () {
    Http::fake([
        'http://93.184.216.34/' => Http::response(rssFeedFetcherTestFixture('html_with_alternate.html')),
        'http://93.184.216.34/feed.xml' => Http::response(rssFeedFetcherTestFixture('html_with_alternate.html')),
    ]);

    expect(rssFeedFetcherTestErrorKey('http://93.184.216.34/', allowDiscovery: true))->toBe('create.feeds.errors.not_a_feed');

    Http::assertSentCount(2);
});

test('a discovered feed on a private host is blocked and never requested', function () {
    Http::fake([
        'http://93.184.216.34/' => Http::response('<html><head><link rel="alternate" type="application/rss+xml" href="http://127.0.0.1/feed.xml"></head></html>'),
        'http://127.0.0.1/*' => Http::response(rssFeedFetcherTestFixture('announcekit_rss.xml')),
    ]);

    expect(rssFeedFetcherTestErrorKey('http://93.184.216.34/', allowDiscovery: true))->toBe('create.feeds.errors.blocked_url');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

test('discovery is skipped once the time budget is spent', function () {
    config()->set('trypost.rss_feeds.fetch_budget_seconds', 10);

    Http::fake([
        'http://93.184.216.34/' => function () {
            Carbon::setTestNow(now()->addSeconds(11));

            return Http::response(rssFeedFetcherTestFixture('html_with_alternate.html'));
        },
        'http://93.184.216.34/feed.xml' => Http::response(rssFeedFetcherTestFixture('announcekit_rss.xml')),
    ]);

    expect(rssFeedFetcherTestErrorKey('http://93.184.216.34/', allowDiscovery: true))->toBe('create.feeds.errors.unreachable');

    Http::assertSentCount(1);
});

test('a redirect after the time budget is spent is not followed', function () {
    config()->set('trypost.rss_feeds.fetch_budget_seconds', 10);

    Http::fake([
        'http://93.184.216.34/feed.xml' => function () {
            Carbon::setTestNow(now()->addSeconds(11));

            return Http::response('', 301, ['Location' => 'https://1.1.1.1/rss.xml']);
        },
        'https://1.1.1.1/rss.xml' => Http::response(rssFeedFetcherTestFixture('announcekit_rss.xml')),
    ]);

    expect(rssFeedFetcherTestErrorKey('http://93.184.216.34/feed.xml'))->toBe('create.feeds.errors.unreachable');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '1.1.1.1'));
});
