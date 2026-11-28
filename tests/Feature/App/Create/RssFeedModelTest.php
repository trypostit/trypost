<?php

declare(strict_types=1);

use App\Models\RssFeed;
use App\Models\RssFeedCollection;
use App\Models\RssFeedItem;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;

test('two items with the same guid hash in one feed violate the unique index', function () {
    $feed = RssFeed::factory()->create();
    $hash = RssFeedItem::hashGuid('445968');

    RssFeedItem::factory()->for($feed, 'feed')->create(['guid_hash' => $hash]);

    expect(fn () => RssFeedItem::factory()->for($feed, 'feed')->create(['guid_hash' => $hash]))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a 2048 character url is stored', function () {
    $url = 'https://example.com/'.str_repeat('a', 2048 - strlen('https://example.com/'));

    $feed = RssFeed::factory()->create(['url' => $url]);

    expect(strlen($feed->fresh()->url))->toBe(2048);
});

test('deleting a collection uncollects its feeds', function () {
    $collection = RssFeedCollection::factory()->create();
    $feed = RssFeed::factory()->inCollection($collection)->create();

    $collection->delete();

    expect($feed->fresh()->rss_feed_collection_id)->toBeNull();
});

test('deleting a feed deletes its items', function () {
    $feed = RssFeed::factory()->create();
    RssFeedItem::factory()->count(2)->for($feed, 'feed')->create();

    $feed->delete();

    expect(RssFeedItem::query()->count())->toBe(0);
});

test('deleting the workspace cascades collections, feeds and items', function () {
    $workspace = Workspace::factory()->create();
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $workspace->id]);
    $feed = RssFeed::factory()->inCollection($collection)->create();
    RssFeedItem::factory()->count(2)->for($feed, 'feed')->create();

    $workspace->delete();

    expect(RssFeedCollection::query()->count())->toBe(0)
        ->and(RssFeed::query()->count())->toBe(0)
        ->and(RssFeedItem::query()->count())->toBe(0);
});

test('the due scope returns feeds never fetched or past their next fetch', function () {
    $never = RssFeed::factory()->create(['next_fetch_at' => null]);
    $past = RssFeed::factory()->create(['next_fetch_at' => now()->subMinute()]);
    RssFeed::factory()->create(['next_fetch_at' => now()->addHour()]);

    expect(RssFeed::query()->due()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$never->id, $past->id])->sort()->values()->all());
});

test('the display title prefers the custom title', function () {
    $feed = RssFeed::factory()->make(['title' => 'Source', 'custom_title' => null]);

    expect($feed->display_title)->toBe('Source');

    $feed->custom_title = 'Mine';

    expect($feed->display_title)->toBe('Mine');
});

test('models use their morph aliases', function () {
    expect((new RssFeed)->getMorphClass())->toBe('rssFeed')
        ->and((new RssFeedCollection)->getMorphClass())->toBe('rssFeedCollection')
        ->and((new RssFeedItem)->getMorphClass())->toBe('rssFeedItem');
});

test('the directory and limits are configured', function () {
    $directory = config('trypost.rss_feeds.directory');

    expect(array_keys($directory))->toBe(['favorites', 'tech', 'news', 'business', 'art_media', 'entertainment', 'science'])
        ->and(array_map('count', array_values($directory)))->toBe([7, 23, 15, 14, 14, 12, 12])
        ->and(config('trypost.rss_feeds.poll_interval_minutes'))->toBe(480)
        ->and(config('trypost.rss_feeds.max_feeds_per_workspace'))->toBe(50);

    foreach ($directory as $entries) {
        $urls = array_column($entries, 'url');

        expect($urls)->toBe(array_values(array_unique($urls)));

        foreach ($entries as $entry) {
            expect($entry['name'])->toBeString()->not->toBe('')
                ->and($entry['url'])->toStartWith('https://');
        }
    }
});

test('url variants of one feed cannot be added twice to a workspace', function () {
    $workspace = Workspace::factory()->create();

    RssFeed::factory()->create(['workspace_id' => $workspace->id, 'url' => 'http://example.com/feed/']);

    expect(fn () => RssFeed::factory()->create(['workspace_id' => $workspace->id, 'url' => 'https://EXAMPLE.com/feed']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the same feed may be added to two workspaces', function () {
    RssFeed::factory()->create(['url' => 'http://example.com/feed/']);
    RssFeed::factory()->create(['url' => 'https://EXAMPLE.com/feed']);

    expect(RssFeed::query()->count())->toBe(2);
});
