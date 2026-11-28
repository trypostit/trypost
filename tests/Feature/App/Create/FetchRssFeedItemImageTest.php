<?php

declare(strict_types=1);

use App\Actions\RssFeed\SyncRssFeedItems;
use App\Enums\RssFeed\Format;
use App\Jobs\RssFeed\FetchRssFeedItemImage;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Services\Http\SafeHttpFetcher;
use App\Services\RssFeed\OgImageExtractor;
use App\Services\RssFeed\ParsedRssFeed;
use App\Services\RssFeed\ParsedRssFeedItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fetchRssFeedItemImageTestFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/fixtures/feeds/{$name}"));
}

function fetchRssFeedItemImageTestItem(string $url, array $attributes = []): RssFeedItem
{
    return RssFeedItem::factory()->create(['url' => $url, 'image_url' => null, 'image_checked_at' => null, ...$attributes]);
}

test('stores the og:image of the item page', function () {
    Http::fake(['http://93.184.216.34/post' => Http::response(fetchRssFeedItemImageTestFixture('page_with_og_image.html'))]);
    $item = fetchRssFeedItemImageTestItem('http://93.184.216.34/post');

    FetchRssFeedItemImage::dispatchSync($item);

    expect($item->refresh()->image_url)->toBe('https://cdn.example.com/og.jpg?w=1200&h=630')
        ->and($item->image_checked_at)->not->toBeNull();
});

test('uses twitter:image when there is no og:image', function () {
    Http::fake(['http://93.184.216.34/post' => Http::response(fetchRssFeedItemImageTestFixture('page_with_twitter_image.html'))]);
    $item = fetchRssFeedItemImageTestItem('http://93.184.216.34/post');

    FetchRssFeedItemImage::dispatchSync($item);

    expect($item->refresh()->image_url)->toBe('https://cdn.example.com/twitter.jpg');
});

test('resolves a relative og:image against the final page url', function () {
    Http::fake([
        'http://93.184.216.34/post' => Http::response('', 301, ['Location' => 'https://93.184.216.34/articles/post']),
        'https://93.184.216.34/articles/post' => Http::response(fetchRssFeedItemImageTestFixture('page_relative_og_image.html')),
    ]);
    $item = fetchRssFeedItemImageTestItem('http://93.184.216.34/post');

    FetchRssFeedItemImage::dispatchSync($item);

    expect($item->refresh()->image_url)->toBe('https://93.184.216.34/img/a.jpg');
});

test('drops an insecure og:image', function () {
    Http::fake(['http://93.184.216.34/post' => Http::response('<meta property="og:image" content="http://cdn.example.com/a.jpg">')]);
    $item = fetchRssFeedItemImageTestItem('http://93.184.216.34/post');

    FetchRssFeedItemImage::dispatchSync($item);

    expect($item->refresh()->image_url)->toBeNull()
        ->and($item->image_checked_at)->not->toBeNull();
});

test('a page without og tags is checked once and never again', function () {
    Queue::fake();
    Http::fake(['http://93.184.216.34/post' => Http::response(fetchRssFeedItemImageTestFixture('page_without_og_tags.html'))]);
    $feed = RssFeed::factory()->create();
    $parsed = new ParsedRssFeed(Format::Rss, 'Feed', null, [
        new ParsedRssFeedItem('guid-1', 'Post', 'http://93.184.216.34/post', null, null, null, CarbonImmutable::now()->subHour()),
    ]);

    SyncRssFeedItems::execute($feed, $parsed);
    $item = $feed->items()->sole();

    (new FetchRssFeedItemImage($item))->handle(app(SafeHttpFetcher::class), app(OgImageExtractor::class));

    expect($item->refresh()->image_url)->toBeNull()
        ->and($item->image_checked_at)->not->toBeNull();

    SyncRssFeedItems::execute($feed, $parsed);

    Queue::assertPushed(FetchRssFeedItemImage::class, 1);
    Http::assertSentCount(1);
});

test('blocked hosts are never requested and the item is marked checked', function (string $url) {
    $page = '<meta property="og:image" content="https://cdn.example.com/a.jpg">';

    Http::fake([
        'http://93.184.216.34/post' => Http::response('', 302, ['Location' => 'http://127.0.0.1/admin']),
        'http://127.0.0.1/*' => Http::response($page),
        'http://169.254.169.254/*' => Http::response($page),
    ]);
    $item = fetchRssFeedItemImageTestItem($url);

    FetchRssFeedItemImage::dispatchSync($item);

    expect($item->refresh()->image_url)->toBeNull()
        ->and($item->image_checked_at)->not->toBeNull();

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1') || str_contains($request->url(), '169.254.169.254'));
})->with([
    'loopback' => 'http://127.0.0.1/post',
    'metadata' => 'http://169.254.169.254/latest',
    'redirect to private' => 'http://93.184.216.34/post',
]);

test('only the first bytes of a large page are read', function (int $padding, ?string $expected) {
    config()->set('trypost.rss_feeds.og_image_max_page_bytes', 256 * 1024);
    $html = '<html><head>'.str_repeat('<!-- padding -->', intdiv($padding, 16)).'<meta property="og:image" content="https://cdn.example.com/a.jpg"></head><body>'.str_repeat('x', 300 * 1024).'</body></html>';

    Http::fake(['http://93.184.216.34/post' => Http::response($html)]);
    $item = fetchRssFeedItemImageTestItem('http://93.184.216.34/post');

    FetchRssFeedItemImage::dispatchSync($item);

    expect($item->refresh()->image_url)->toBe($expected)
        ->and($item->image_checked_at)->not->toBeNull();
})->with([
    'tag inside the cap' => [1024, 'https://cdn.example.com/a.jpg'],
    'tag past the cap' => [260 * 1024, null],
]);

test('items that already have an image or were checked are skipped', function (array $attributes) {
    Http::fake();
    $item = fetchRssFeedItemImageTestItem('http://93.184.216.34/post', $attributes);

    FetchRssFeedItemImage::dispatchSync($item);

    Http::assertNothingSent();
})->with([
    'has image' => [['image_url' => 'https://cdn.example.com/feed.jpg']],
    'checked' => [['image_checked_at' => '2026-09-30 00:00:00']],
]);

test('an image stored while the page was being fetched is not overwritten', function () {
    $item = fetchRssFeedItemImageTestItem('http://93.184.216.34/post');
    Http::fake(function () use ($item) {
        RssFeedItem::query()->whereKey($item->id)->update(['image_url' => 'https://cdn.example.com/feed.jpg']);

        return Http::response(fetchRssFeedItemImageTestFixture('page_with_og_image.html'));
    });

    FetchRssFeedItemImage::dispatchSync($item);

    expect($item->refresh()->image_url)->toBe('https://cdn.example.com/feed.jpg');
});

test('the job runs on the rss-feeds queue', function () {
    expect((new FetchRssFeedItemImage(RssFeedItem::factory()->make()))->queue)->toBe('rss-feeds');
});
