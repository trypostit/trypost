<?php

declare(strict_types=1);

use App\Actions\RssFeed\SyncRssFeedItems;
use App\Enums\RssFeed\Format;
use App\Jobs\RssFeed\FetchRssFeedItemImage;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Services\RssFeed\ParsedRssFeed;
use App\Services\RssFeed\ParsedRssFeedItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;

/**
 * @param  list<ParsedRssFeedItem>  $items
 */
function syncRssFeedItemsTestFeed(array $items, string $title = 'Source title', Format $format = Format::Atom, ?string $siteUrl = 'https://news.example.com/'): ParsedRssFeed
{
    return new ParsedRssFeed($format, $title, $siteUrl, $items);
}

function syncRssFeedItemsTestItem(string $guid, ?string $publishedAt = '2026-09-30 08:00:00', array $overrides = []): ParsedRssFeedItem
{
    return new ParsedRssFeedItem(
        guid: $guid,
        title: data_get($overrides, 'title', "Title {$guid}"),
        url: array_key_exists('url', $overrides) ? $overrides['url'] : "https://news.example.com/{$guid}",
        excerpt: 'Excerpt',
        imageUrl: data_get($overrides, 'image'),
        author: data_get($overrides, 'author'),
        publishedAt: $publishedAt === null ? null : CarbonImmutable::parse($publishedAt, 'UTC'),
    );
}

beforeEach(function () {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
});

test('a second sync of the same items adds nothing', function () {
    $feed = RssFeed::factory()->create();
    $parsed = syncRssFeedItemsTestFeed([
        syncRssFeedItemsTestItem('a'),
        syncRssFeedItemsTestItem('b'),
        syncRssFeedItemsTestItem('c'),
    ]);

    expect(SyncRssFeedItems::execute($feed, $parsed))->toBe(3)
        ->and(SyncRssFeedItems::execute($feed, $parsed))->toBe(0)
        ->and($feed->items()->count())->toBe(3);
});

test('an existing item keeps its id and date when its title changes', function () {
    $feed = RssFeed::factory()->create();
    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed([syncRssFeedItemsTestItem('a')]));
    $item = $feed->items()->sole();

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed([
        syncRssFeedItemsTestItem('a', '2026-09-29 08:00:00', ['title' => 'Renamed']),
    ]));

    $fresh = $feed->items()->sole();

    expect($fresh->id)->toBe($item->id)
        ->and($fresh->title)->toBe('Renamed')
        ->and($fresh->published_at->equalTo($item->published_at))->toBeTrue();
});

test('only the newest items are kept', function () {
    config()->set('trypost.rss_feeds.max_items_per_feed', 5);
    $feed = RssFeed::factory()->create();

    $items = collect(range(1, 8))
        ->map(fn (int $day): ParsedRssFeedItem => syncRssFeedItemsTestItem("item-{$day}", sprintf('2026-09-%02d 08:00:00', $day)))
        ->all();

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed($items));

    expect($feed->items()->orderBy('published_at')->pluck('title')->all())
        ->toBe(['Title item-4', 'Title item-5', 'Title item-6', 'Title item-7', 'Title item-8']);
});

test('missing, future and pre-1970 dates become the fetch time', function (?string $date, string $expected) {
    $feed = RssFeed::factory()->create();

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed([syncRssFeedItemsTestItem('a', $date)]));

    expect($feed->items()->sole()->published_at->utc()->format('Y-m-d H:i:s'))->toBe($expected);
})->with([
    'missing' => [null, '2026-10-01 12:00:00'],
    'future' => ['2099-01-01 00:00:00', '2026-10-01 12:00:00'],
    'before 1970' => ['1960-01-01 00:00:00', '2026-10-01 12:00:00'],
    'valid' => ['2026-09-30 00:00:00', '2026-09-30 00:00:00'],
]);

test('the feed metadata follows the source and keeps the user fields', function () {
    $feed = RssFeed::factory()->create([
        'url' => 'https://feeds.example.com/rss',
        'title' => 'Old',
        'custom_title' => 'Mine',
        'format' => Format::Rss,
    ]);

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed(
        [syncRssFeedItemsTestItem('a', overrides: ['author' => 'Ada'])],
        title: 'New title',
    ));

    $feed->refresh();

    expect($feed->title)->toBe('New title')
        ->and($feed->format)->toBe(Format::Atom)
        ->and($feed->site_url)->toBe('https://news.example.com/')
        ->and($feed->icon_url)->toBe('https://news.example.com/favicon.ico')
        ->and($feed->custom_title)->toBe('Mine')
        ->and($feed->url)->toBe('https://feeds.example.com/rss')
        ->and($feed->items()->sole()->author)->toBe('Ada');
});

test('the icon falls back to the feed host without a site url', function () {
    $feed = RssFeed::factory()->create(['url' => 'https://feeds.example.com/rss']);

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed([], siteUrl: null));

    expect($feed->refresh()->icon_url)->toBe('https://feeds.example.com/favicon.ico');
});

test('image lookups are queued only for new recent items without an image', function () {
    config()->set('trypost.rss_feeds.og_image_max_items_per_poll', 20);
    $feed = RssFeed::factory()->create();

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed([
        syncRssFeedItemsTestItem('imageless'),
        syncRssFeedItemsTestItem('with-image', overrides: ['image' => 'https://cdn.example.com/a.jpg']),
        syncRssFeedItemsTestItem('old', '2026-09-01 00:00:00'),
        syncRssFeedItemsTestItem('no-link', overrides: ['url' => null]),
    ]));

    $imageless = $feed->items()->where('guid_hash', RssFeedItem::hashGuid('imageless'))->sole();

    Queue::assertPushedOn('rss-feeds', FetchRssFeedItemImage::class);
    Queue::assertPushed(FetchRssFeedItemImage::class, 1);
    Queue::assertPushed(FetchRssFeedItemImage::class, fn (FetchRssFeedItemImage $job): bool => $job->item->is($imageless));
});

test('image lookups are capped per poll and never repeated by a later sync', function () {
    config()->set('trypost.rss_feeds.og_image_max_items_per_poll', 20);
    $feed = RssFeed::factory()->create();

    $items = collect(range(1, 25))
        ->map(fn (int $minute): ParsedRssFeedItem => syncRssFeedItemsTestItem("item-{$minute}", sprintf('2026-09-30 08:%02d:00', $minute)))
        ->all();

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed($items));

    Queue::assertPushed(FetchRssFeedItemImage::class, 20);
    Queue::assertNotPushed(FetchRssFeedItemImage::class, fn (FetchRssFeedItemImage $job): bool => in_array($job->item->title, ['Title item-1', 'Title item-5'], true));

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed($items));

    Queue::assertPushed(FetchRssFeedItemImage::class, 20);
});

test('a re-sync keeps an image found on the item page', function () {
    $feed = RssFeed::factory()->create();
    $parsed = syncRssFeedItemsTestFeed([syncRssFeedItemsTestItem('a')]);

    SyncRssFeedItems::execute($feed, $parsed);
    $feed->items()->sole()->update(['image_url' => 'https://cdn.example.com/og.jpg', 'image_checked_at' => now()]);

    SyncRssFeedItems::execute($feed, $parsed);

    expect($feed->items()->sole()->image_url)->toBe('https://cdn.example.com/og.jpg');
});

test('a feed image replaces the stored one', function () {
    $feed = RssFeed::factory()->create();

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed([syncRssFeedItemsTestItem('a', overrides: ['image' => 'https://cdn.example.com/1.jpg'])]));
    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed([syncRssFeedItemsTestItem('a', overrides: ['image' => 'https://cdn.example.com/2.jpg'])]));

    expect($feed->items()->sole()->image_url)->toBe('https://cdn.example.com/2.jpg');
});

test('a feed longer than the cap syncs its newest items and settles', function () {
    config()->set('trypost.rss_feeds.max_items_per_feed', 5);
    $feed = RssFeed::factory()->create();

    $items = collect(range(1, 8))
        ->map(fn (int $day): ParsedRssFeedItem => syncRssFeedItemsTestItem("item-{$day}", sprintf('2026-09-%02d 08:00:00', $day)))
        ->push(syncRssFeedItemsTestItem('undated', null))
        ->shuffle()
        ->all();

    expect(SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed($items)))->toBe(5);

    $ids = $feed->items()->orderBy('id')->pluck('id')->all();

    expect(SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed($items)))->toBe(0)
        ->and($feed->items()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and($feed->items()->pluck('title')->sort()->values()->all())
        ->toBe(['Title item-5', 'Title item-6', 'Title item-7', 'Title item-8', 'Title undated']);
});

test('a huge feed syncs without hitting placeholder limits', function () {
    config()->set('trypost.rss_feeds.max_items_per_feed', 7000);
    $feed = RssFeed::factory()->create();

    $items = collect(range(1, 7000))
        ->map(fn (int $index): ParsedRssFeedItem => syncRssFeedItemsTestItem("item-{$index}", CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC')->addMinutes($index)->toDateTimeString()))
        ->all();

    expect(SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed($items)))->toBe(7000)
        ->and($feed->items()->count())->toBe(7000);
});

test('items stored with the fetch time keep their rank on later syncs', function (?string $oddDate) {
    config()->set('trypost.rss_feeds.max_items_per_feed', 5);
    config()->set('trypost.rss_feeds.og_image_max_age_days', 14);
    $feed = RssFeed::factory()->create();

    $items = collect(range(1, 8))
        ->map(fn (int $day): ParsedRssFeedItem => syncRssFeedItemsTestItem("item-{$day}", sprintf('2026-09-%02d 08:00:00', $day + 20)))
        ->push(syncRssFeedItemsTestItem('odd', $oddDate))
        ->all();

    SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed([syncRssFeedItemsTestItem('odd', $oddDate)]));
    $this->travel(8)->hours();

    expect(SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed($items)))->toBe(4);

    $ids = $feed->items()->orderBy('id')->pluck('id')->all();
    $jobs = Queue::pushed(FetchRssFeedItemImage::class)->count();

    foreach ([1, 2] as $run) {
        $this->travel(8)->hours();

        expect(SyncRssFeedItems::execute($feed, syncRssFeedItemsTestFeed($items)))->toBe(0)
            ->and($feed->items()->orderBy('id')->pluck('id')->all())->toBe($ids);
    }

    expect(Queue::pushed(FetchRssFeedItemImage::class)->count())->toBe($jobs)
        ->and($feed->items()->where('guid_hash', RssFeedItem::hashGuid('odd'))->exists())->toBeTrue();
})->with([
    'epoch' => ['1970-01-01 00:00:00'],
    'missing' => [null],
    'future' => ['2099-01-01 00:00:00'],
]);
