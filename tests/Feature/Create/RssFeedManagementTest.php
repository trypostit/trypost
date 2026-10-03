<?php

declare(strict_types=1);

use App\Enums\RssFeed\Format;
use App\Jobs\RssFeed\FetchRssFeed;
use App\Models\Idea;
use App\Models\RssFeed;
use App\Models\RssFeedCollection;
use App\Models\RssFeedItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function rssFeedManagementFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/fixtures/feeds/{$name}"));
}

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
});

test('adding a feed stores it with its items and opens it', function () {
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(rssFeedManagementFixture('announcekit_rss.xml'))]);

    $response = $this->actingAs($this->user)->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/feed.xml']);

    $feed = RssFeed::query()->sole();

    $response->assertRedirect(route('app.create.feeds.show', $feed))
        ->assertSessionMissing('flash');

    expect($feed->workspace_id)->toBe($this->workspace->id)
        ->and($feed->format)->toBe(Format::Rss)
        ->and($feed->title)->toBe('AnnounceKit Product Updates')
        ->and($feed->rss_feed_collection_id)->toBeNull()
        ->and($feed->last_succeeded_at)->not->toBeNull()
        ->and($feed->next_fetch_at->isFuture())->toBeTrue()
        ->and($feed->items()->count())->toBe(3);
});

test('adding an atom or json feed stores its format', function (string $fixture, Format $format) {
    Http::fake(['http://93.184.216.34/feed' => Http::response(rssFeedManagementFixture($fixture))]);

    $this->actingAs($this->user)->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/feed'])->assertRedirect();

    expect(RssFeed::query()->sole()->format)->toBe($format);
})->with([
    'atom' => ['announcekit_atom.xml', Format::Atom],
    'json feed' => ['announcekit_jsonfeed.json', Format::JsonFeed],
]);

test('adding from the directory stays on the page', function () {
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(rssFeedManagementFixture('announcekit_rss.xml'))]);

    $this->actingAs($this->user)
        ->from(route('app.create.ideas.index'))
        ->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/feed.xml', 'source' => 'explore'])
        ->assertRedirect(route('app.create.ideas.index'));

    expect(RssFeed::query()->count())->toBe(1);
});

test('adding a feed can place it in one of the workspace collections', function () {
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(rssFeedManagementFixture('announcekit_rss.xml'))]);
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/feed.xml', 'rss_feed_collection_id' => $collection->id])
        ->assertRedirect();

    expect(RssFeed::query()->sole()->rss_feed_collection_id)->toBe($collection->id);
});

test('adding a feed into a collection of another workspace is refused', function () {
    Http::fake();
    $other = Workspace::factory()->create();
    $foreign = RssFeedCollection::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/feed.xml', 'rss_feed_collection_id' => $foreign->id])
        ->assertSessionHasErrors('rss_feed_collection_id');

    expect(RssFeed::query()->count())->toBe(0);
});

test('a private address is refused', function () {
    Http::fake();

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'http://127.0.0.1/feed.xml'])
        ->assertSessionHasErrors(['url' => __('create.feeds.errors.blocked_url')]);

    expect(RssFeed::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a site url is replaced by the feed it links to', function () {
    Http::fake([
        'http://93.184.216.34/' => Http::response(rssFeedManagementFixture('html_with_alternate.html')),
        'http://93.184.216.34/feed.xml' => Http::response(rssFeedManagementFixture('announcekit_rss.xml')),
    ]);

    $this->actingAs($this->user)->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/'])->assertRedirect();

    expect(RssFeed::query()->sole()->url)->toBe('http://93.184.216.34/feed.xml');
});

test('a page without a feed is not a feed', function () {
    Http::fake(['http://93.184.216.34/' => Http::response(rssFeedManagementFixture('not_a_feed.html'))]);

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/'])
        ->assertSessionHasErrors(['url' => __('create.feeds.errors.not_a_feed')]);
});

test('the workspace feed limit stops an add before any request', function () {
    config()->set('trypost.rss_feeds.max_feeds_per_workspace', 50);
    Http::fake();
    RssFeed::factory()->count(50)->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/feed.xml'])
        ->assertSessionHasErrors(['url' => __('create.feeds.errors.limit_reached', ['max' => 50])]);

    Http::assertNothingSent();
    expect(RssFeed::query()->count())->toBe(50);
});

test('a feed can be renamed, moved into a collection and back', function () {
    $feed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'Source']);
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->put(route('app.create.feeds.update', $feed), ['custom_title' => 'Mine'])
        ->assertRedirect()->assertSessionMissing('flash');
    expect($feed->refresh()->display_title)->toBe('Mine');

    $this->actingAs($this->user)->put(route('app.create.feeds.update', $feed), ['rss_feed_collection_id' => $collection->id]);
    expect($feed->refresh()->rss_feed_collection_id)->toBe($collection->id)
        ->and($feed->custom_title)->toBe('Mine');

    $this->actingAs($this->user)->put(route('app.create.feeds.update', $feed), ['rss_feed_collection_id' => null]);
    expect($feed->refresh()->rss_feed_collection_id)->toBeNull();

    $this->actingAs($this->user)->put(route('app.create.feeds.update', $feed), ['custom_title' => '']);
    expect($feed->refresh()->display_title)->toBe('Source');
});

test('a feed cannot move into another workspace collection', function () {
    $feed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $foreign = RssFeedCollection::factory()->create();

    $this->actingAs($this->user)
        ->put(route('app.create.feeds.update', $feed), ['rss_feed_collection_id' => $foreign->id])
        ->assertSessionHasErrors('rss_feed_collection_id');

    expect($feed->refresh()->rss_feed_collection_id)->toBeNull();
});

test('a feed can be deleted with its items', function () {
    $feed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    RssFeedItem::factory()->for($feed, 'feed')->create();

    $this->actingAs($this->user)->delete(route('app.create.feeds.destroy', $feed))->assertRedirect()->assertSessionMissing('flash');

    expect(RssFeed::query()->count())->toBe(0)
        ->and(RssFeedItem::query()->count())->toBe(0);
});

test('deleting the feed or collection being viewed returns to all feeds, elsewhere it goes back', function () {
    $viewed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $other = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $this->workspace->id]);
    $otherCollection = RssFeedCollection::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)
        ->from(route('app.create.feeds.show', $viewed))
        ->delete(route('app.create.feeds.destroy', $viewed))
        ->assertRedirect(route('app.create.feeds.index'));

    $this->actingAs($this->user)
        ->from(route('app.create.ideas.index'))
        ->delete(route('app.create.feeds.destroy', $other))
        ->assertRedirect(route('app.create.ideas.index'));

    $this->actingAs($this->user)
        ->from(route('app.create.feeds.collections.show', $collection))
        ->delete(route('app.create.feed-collections.destroy', $collection))
        ->assertRedirect(route('app.create.feeds.index'));

    $this->actingAs($this->user)
        ->from(route('app.create.feeds.index'))
        ->delete(route('app.create.feed-collections.destroy', $otherCollection))
        ->assertRedirect(route('app.create.feeds.index'));

    expect(RssFeed::query()->count())->toBe(0)
        ->and(RssFeedCollection::query()->count())->toBe(0);
});

test('collections can be created, renamed and deleted without losing feeds', function () {
    $this->actingAs($this->user)->post(route('app.create.feed-collections.store'), ['name' => 'Tech'])->assertRedirect();
    $this->actingAs($this->user)->post(route('app.create.feed-collections.store'), ['name' => 'News']);

    $collections = RssFeedCollection::query()->orderBy('position')->get();
    expect($collections->pluck('name')->all())->toBe(['Tech', 'News'])
        ->and($collections->pluck('position')->all())->toBe([0, 1]);

    $collection = $collections->first();
    $this->actingAs($this->user)->put(route('app.create.feed-collections.update', $collection), ['name' => 'Technology']);
    expect($collection->refresh()->name)->toBe('Technology');

    $feed = RssFeed::factory()->inCollection($collection)->create();
    $this->actingAs($this->user)->delete(route('app.create.feed-collections.destroy', $collection))->assertRedirect();

    expect(RssFeedCollection::query()->count())->toBe(1)
        ->and($feed->refresh()->rss_feed_collection_id)->toBeNull();
});

test('collection names are required and short', function (mixed $name) {
    $this->actingAs($this->user)
        ->post(route('app.create.feed-collections.store'), ['name' => $name])
        ->assertSessionHasErrors('name');
})->with([
    'missing' => [null],
    'too long' => [str_repeat('a', 61)],
]);

test('refresh queues feeds outside the cooldown and stamps them', function () {
    Queue::fake();
    $never = RssFeed::factory()->create(['workspace_id' => $this->workspace->id, 'last_fetched_at' => null]);
    $stale = RssFeed::factory()->create(['workspace_id' => $this->workspace->id, 'last_fetched_at' => now()->subMinutes(10)]);
    $recent = RssFeed::factory()->create(['workspace_id' => $this->workspace->id, 'last_fetched_at' => now()->subMinutes(2)]);
    RssFeed::factory()->create(['last_fetched_at' => null]);

    $this->actingAs($this->user)->post(route('app.create.feeds.refresh'))->assertRedirect()->assertSessionMissing('flash');

    Queue::assertPushed(FetchRssFeed::class, 2);
    Queue::assertPushed(FetchRssFeed::class, fn (FetchRssFeed $job): bool => $job->feed->is($never));
    Queue::assertPushed(FetchRssFeed::class, fn (FetchRssFeed $job): bool => $job->feed->is($stale));

    expect($never->refresh()->refresh_requested_at)->not->toBeNull()
        ->and($stale->refresh()->refresh_requested_at)->not->toBeNull()
        ->and($recent->refresh()->refresh_requested_at)->toBeNull();
});

test('refresh can target one feed or one collection', function () {
    Queue::fake();
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $this->workspace->id]);
    $collected = RssFeed::factory()->inCollection($collection)->create();
    $single = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);

    $this->actingAs($this->user)->post(route('app.create.feeds.refresh'), ['rss_feed_id' => $single->id]);
    Queue::assertPushed(FetchRssFeed::class, 1);
    Queue::assertPushed(FetchRssFeed::class, fn (FetchRssFeed $job): bool => $job->feed->is($single));

    $this->actingAs($this->user)->post(route('app.create.feeds.refresh'), ['rss_feed_collection_id' => $collection->id]);
    Queue::assertPushed(FetchRssFeed::class, 2);
    Queue::assertPushed(FetchRssFeed::class, fn (FetchRssFeed $job): bool => $job->feed->is($collected));
});

test('refresh refuses another workspace feed and is rate limited', function () {
    Queue::fake();
    $foreign = RssFeed::factory()->create();

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.refresh'), ['rss_feed_id' => $foreign->id])
        ->assertSessionHasErrors('rss_feed_id');

    foreach (range(2, 12) as $attempt) {
        $this->actingAs($this->user)->post(route('app.create.feeds.refresh'))->assertRedirect();
    }

    $this->actingAs($this->user)->post(route('app.create.feeds.refresh'))->assertTooManyRequests();
});

test('saving an item as an idea puts it in Unassigned', function (?string $excerpt, string $body) {
    $feed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $item = RssFeedItem::factory()->for($feed, 'feed')->create([
        'title' => 'Knowledge Base beta',
        'excerpt' => $excerpt,
        'url' => 'https://example.com/kb',
        'image_url' => null,
    ]);

    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertRedirect()->assertSessionMissing('flash');

    $idea = Idea::query()->sole();

    expect($idea->workspace_id)->toBe($this->workspace->id)
        ->and($idea->user_id)->toBe($this->user->id)
        ->and($idea->idea_stage_id)->toBeNull()
        ->and($idea->title)->toBe('Knowledge Base beta')
        ->and($idea->body)->toBe($body)
        ->and($idea->media)->toBe([]);
})->with([
    'with excerpt' => ['Your changelog got a new friend.', "Your changelog got a new friend.\n\nhttps://example.com/kb"],
    'without excerpt' => [null, 'https://example.com/kb'],
]);

test('another workspace resources are forbidden', function () {
    $feed = RssFeed::factory()->create();
    $collection = RssFeedCollection::factory()->create();
    $item = RssFeedItem::factory()->create();

    $this->actingAs($this->user)->put(route('app.create.feeds.update', $feed), ['custom_title' => 'x'])->assertForbidden();
    $this->actingAs($this->user)->delete(route('app.create.feeds.destroy', $feed))->assertForbidden();
    $this->actingAs($this->user)->get(route('app.create.feeds.show', $feed))->assertForbidden();
    $this->actingAs($this->user)->put(route('app.create.feed-collections.update', $collection), ['name' => 'x'])->assertForbidden();
    $this->actingAs($this->user)->delete(route('app.create.feed-collections.destroy', $collection))->assertForbidden();
    $this->actingAs($this->user)->post(route('app.create.feed-items.idea', $item))->assertForbidden();
    $this->actingAs($this->user)->post(route('app.create.feed-items.import-image', $item))->assertForbidden();

    expect(RssFeed::query()->count())->toBe(2)
        ->and(RssFeedCollection::query()->count())->toBe(1)
        ->and(Idea::query()->count())->toBe(0);
});

test('a user outside the workspace cannot change feeds', function () {
    Http::fake();
    $outsider = workspaceOutsider($this->workspace);
    $feed = RssFeed::factory()->create(['workspace_id' => $this->workspace->id]);
    $collection = RssFeedCollection::factory()->create(['workspace_id' => $this->workspace->id]);
    $item = RssFeedItem::factory()->for($feed, 'feed')->create();

    $this->actingAs($outsider)->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/feed.xml'])->assertForbidden();
    $this->actingAs($outsider)->post(route('app.create.feeds.refresh'))->assertForbidden();
    $this->actingAs($outsider)->put(route('app.create.feeds.update', $feed), ['custom_title' => 'x'])->assertForbidden();
    $this->actingAs($outsider)->delete(route('app.create.feeds.destroy', $feed))->assertForbidden();
    $this->actingAs($outsider)->post(route('app.create.feed-collections.store'), ['name' => 'x'])->assertForbidden();
    $this->actingAs($outsider)->put(route('app.create.feed-collections.update', $collection), ['name' => 'x'])->assertForbidden();
    $this->actingAs($outsider)->delete(route('app.create.feed-collections.destroy', $collection))->assertForbidden();
    $this->actingAs($outsider)->post(route('app.create.feed-items.idea', $item))->assertForbidden();
    $this->actingAs($outsider)->post(route('app.create.feed-items.import-image', $item))->assertForbidden();

    Http::assertNothingSent();
});

test('the feed limit holds when another add lands during the fetch', function () {
    config()->set('trypost.rss_feeds.max_feeds_per_workspace', 50);
    RssFeed::factory()->count(49)->create(['workspace_id' => $this->workspace->id]);
    $workspace = $this->workspace;

    Http::fake([
        'http://93.184.216.34/feed.xml' => function () use ($workspace) {
            RssFeed::factory()->create(['workspace_id' => $workspace->id]);

            return Http::response(rssFeedManagementFixture('announcekit_rss.xml'));
        },
    ]);

    $this->actingAs($this->user)
        ->post(route('app.create.feeds.store'), ['url' => 'http://93.184.216.34/feed.xml'])
        ->assertSessionHasErrors(['url' => __('create.feeds.errors.limit_reached', ['max' => 50])]);

    expect(RssFeed::query()->count())->toBe(50);
});
