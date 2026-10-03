<?php

declare(strict_types=1);

use App\Models\RssFeed;
use App\Models\RssFeedCollection;
use App\Models\RssFeedItem;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
});

test('all feeds lists every item newest first, one page at a time', function () {
    $size = (int) config('app.pagination.default');
    $first = RssFeed::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'Source', 'custom_title' => 'Mine', 'icon_url' => 'https://a.example.com/favicon.ico']);
    $second = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    RssFeedItem::factory()->create();

    foreach (range(1, $size + 1) as $i) {
        RssFeedItem::factory()->for($i % 2 === 0 ? $first : $second, 'feed')->create(['published_at' => now()->subMinutes($size + 2 - $i)]);
    }

    $tie = now()->subMinute();
    $tied = RssFeedItem::factory()->count(2)->for($first, 'feed')->create(['published_at' => $tie->addSeconds(30)]);
    $expected = $tied->sortByDesc('id')->pluck('id')->values()->all();

    $this->actingAs($this->user)->get(route('app.create.feeds.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('create/Feeds')
            ->where('scope', ['kind' => 'all'])
            ->missing('modal')
            ->missing('directory')
            ->has('feeds', 2)
            ->has('items.data', $size)
            ->where('items.data.0.id', $expected[0])
            ->where('items.data.1.id', $expected[1])
            ->where('items.data.0.feed', ['id' => $first->id, 'display_title' => 'Mine', 'icon_url' => 'https://a.example.com/favicon.ico'])
            ->where('items.meta.current_page', 1)
            ->where('items.meta.last_page', 2));
});

test('another workspace feeds, collections and items never reach the lists', function () {
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $this->workspace->id]);
    $empty = RssFeedCollection::factory()->create(['workspace_id' => $this->workspace->id]);
    $mine = RssFeed::factory()->count(2)->inCollection($collection)->create();
    $mineItem = RssFeedItem::factory()->for($mine->first(), 'feed')->create();
    $foreignCollection = RssFeedCollection::factory()->create();
    $foreignFeed = RssFeed::factory()->inCollection($foreignCollection)->create();
    RssFeedItem::factory()->count(3)->for($foreignFeed, 'feed')->create();

    $this->actingAs($this->user)->get(route('app.create.feeds.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('items.data', 1)
            ->where('items.data.0.id', $mineItem->id)
            ->has('feeds', 2)
            ->has('collections', 2)
            ->where('collections', fn (Collection $collections): bool => $collections->pluck('feeds_count', 'id')->sortKeys()->all() === collect([$collection->id => 2, $empty->id => 0])->sortKeys()->all()));

    $this->actingAs($this->user)->get(route('app.create.feeds.collections.show', $collection))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope.collection.feeds_count', 2)
            ->has('items.data', 1));
});

test('the items list runs the same number of queries however many items there are', function () {
    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)->get(route('app.create.feeds.index'))->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    $feeds = RssFeed::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);
    RssFeedItem::factory()->for($feeds->first(), 'feed')->create();
    $countQueries();
    $few = $countQueries();

    foreach ($feeds as $feed) {
        RssFeedItem::factory()->count(8)->for($feed, 'feed')->create();
    }
    $many = $countQueries();

    expect($many)->toBe($few);
});

test('a feed page and a collection page narrow the items', function () {
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $this->workspace->id]);
    $collected = RssFeed::factory()->inCollection($collection)->create();
    $loose = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $collectedItem = RssFeedItem::factory()->for($collected, 'feed')->create();
    $looseItem = RssFeedItem::factory()->for($loose, 'feed')->create();

    $this->actingAs($this->user)->get(route('app.create.feeds.show', $loose))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope.kind', 'feed')
            ->where('scope.feed.id', $loose->id)
            ->has('items.data', 1)
            ->where('items.data.0.id', $looseItem->id)
            ->has('feeds', 2));

    $this->actingAs($this->user)->get(route('app.create.feeds.collections.show', $collection))
        ->assertInertia(fn (Assert $page) => $page
            ->where('scope.kind', 'collection')
            ->where('scope.collection.id', $collection->id)
            ->where('scope.collection.feeds_count', 1)
            ->has('items.data', 1)
            ->where('items.data.0.id', $collectedItem->id)
            ->has('collections', 1)
            ->where('collections.0.feeds_count', 1));
});

test('last refreshed and refreshing follow the scope', function () {
    $idle = RssFeed::factory()->create([
        'workspace_id' => $this->workspace->id,
        'last_succeeded_at' => now()->subHours(3),
        'last_fetched_at' => now()->subHours(3),
        'refresh_requested_at' => now()->subHours(4),
    ]);
    $busy = RssFeed::factory()->create([
        'workspace_id' => $this->workspace->id,
        'last_succeeded_at' => now()->subHour(),
        'last_fetched_at' => now()->subHour(),
        'refresh_requested_at' => now()->subMinute(),
    ]);

    $this->actingAs($this->user)->get(route('app.create.feeds.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('last_refreshed_at', now()->subHour()->toISOString())
            ->where('refreshing', true));

    $this->actingAs($this->user)->get(route('app.create.feeds.show', $idle))
        ->assertInertia(fn (Assert $page) => $page
            ->where('last_refreshed_at', now()->subHours(3)->toISOString())
            ->where('refreshing', false));

    $busy->update(['last_fetched_at' => null, 'refresh_requested_at' => now()]);

    $this->actingAs($this->user)->get(route('app.create.feeds.show', $busy))
        ->assertInertia(fn (Assert $page) => $page->where('refreshing', true));
});

test('a workspace without refreshed feeds has no last refreshed time', function () {
    $this->actingAs($this->user)->get(route('app.create.feeds.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('last_refreshed_at', null)
            ->where('refreshing', false)
            ->has('items.data', 0));
});

test('the directory reload runs the same number of queries however many feeds are subscribed', function () {
    $this->actingAs($this->user)->get(route('app.create.feeds.index'))->assertOk();

    $countQueries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user)
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Inertia-Version' => Inertia::getVersion(),
                'X-Inertia-Partial-Component' => 'create/Feeds',
                'X-Inertia-Partial-Data' => 'directory',
            ])
            ->get(route('app.create.feeds.index'))
            ->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $countQueries();
    $few = $countQueries();

    RssFeed::factory()->count(6)->create(['workspace_id' => $this->workspace->id]);

    expect($countQueries())->toBe($few);
});

test('the directory is only sent on a partial reload and marks feeds already added', function () {
    $verge = RssFeed::factory()->create(['workspace_id' => $this->workspace->id, 'url' => 'http://www.theverge.com/rss/index.xml/']);

    $this->actingAs($this->user)->get(route('app.create.feeds.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('directory')
            ->reloadOnly('directory', fn (Assert $reload) => $reload
                ->missing('items')
                ->missing('feeds')
                ->has('directory', 7)
                ->where('directory.0.key', 'favorites')
                ->where('directory.1.key', 'tech')
                ->has('directory.1.entries', 23)
                ->where('directory.1.entries.1', [
                    'name' => 'The Verge',
                    'url' => 'https://www.theverge.com/rss/index.xml',
                    'icon_url' => 'https://www.theverge.com/favicon.ico',
                    'subscribed' => true,
                    'feed_id' => $verge->id,
                ])
                ->where('directory.1.entries.0.subscribed', false)
                ->where('directory.1.entries.0.feed_id', null)));
});

test('the page reports the feed limit', function () {
    RssFeed::factory()->count(2)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->get(route('app.create.feeds.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('create/Feeds')
            ->where('scope', ['kind' => 'all'])
            ->where('limits', ['max_feeds' => 50, 'feeds_count' => 2]));
});

test('another workspace feed or collection is forbidden', function () {
    $this->actingAs($this->user)->get(route('app.create.feeds.show', RssFeed::factory()->create()))->assertForbidden();
    $this->actingAs($this->user)->get(route('app.create.feeds.collections.show', RssFeedCollection::factory()->create()))->assertForbidden();
});

test('a user outside the workspace cannot open feeds', function () {
    $outsider = workspaceOutsider($this->workspace);
    $outsider = $outsider->fresh();
    $feed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($outsider)->get(route('app.create.feeds.index'))->assertForbidden();
    $this->actingAs($outsider)->get(route('app.create.feeds.show', $feed))->assertForbidden();
});
