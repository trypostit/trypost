<?php

declare(strict_types=1);

use App\Actions\RssFeed\RecordRssFeedFetch;
use App\Jobs\RssFeed\FetchRssFeed;
use App\Jobs\RssFeed\FetchRssFeedItemImage;
use App\Models\RssFeed;
use App\Services\RssFeed\RssFeedFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function fetchRssFeedTestFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/fixtures/feeds/{$name}"));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00', 'UTC'));
});

test('a successful fetch stores items and schedules the next one in 8 hours', function () {
    Queue::fake([FetchRssFeed::class, FetchRssFeedItemImage::class]);
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(fetchRssFeedTestFixture('announcekit_rss.xml'))]);
    $feed = RssFeed::factory()->create([
        'url' => 'http://93.184.216.34/feed.xml',
        'consecutive_failures' => 2,
        'last_error' => 'create.feeds.errors.unreachable',
    ]);

    (new FetchRssFeed($feed))->handle(app(RssFeedFetcher::class));

    $feed->refresh();

    expect($feed->items()->count())->toBe(3)
        ->and($feed->title)->toBe('AnnounceKit Product Updates')
        ->and($feed->last_fetched_at->equalTo(now()))->toBeTrue()
        ->and($feed->last_succeeded_at->equalTo(now()))->toBeTrue()
        ->and($feed->consecutive_failures)->toBe(0)
        ->and($feed->last_error)->toBeNull()
        ->and($feed->next_fetch_at->equalTo(now()->addMinutes(480)))->toBeTrue();
});

test('failures back off 8, 16 then 24 hours at most', function () {
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response('', 500)]);
    $succeededAt = now()->subDay();
    $feed = RssFeed::factory()->create(['url' => 'http://93.184.216.34/feed.xml', 'last_succeeded_at' => $succeededAt]);

    foreach ([480, 960, 1440, 1440] as $failures => $minutes) {
        FetchRssFeed::dispatchSync($feed->refresh());
        $feed->refresh();

        expect($feed->consecutive_failures)->toBe($failures + 1)
            ->and($feed->last_error)->toBe('create.feeds.errors.unreachable')
            ->and($feed->next_fetch_at->equalTo(now()->addMinutes($minutes)))->toBeTrue()
            ->and($feed->last_fetched_at->equalTo(now()))->toBeTrue()
            ->and($feed->last_succeeded_at->equalTo($succeededAt))->toBeTrue();
    }
});

test('a body that is not a feed records not_a_feed', function () {
    Http::fake(['http://93.184.216.34/feed.xml' => Http::response(fetchRssFeedTestFixture('html_with_alternate.html'))]);
    $feed = RssFeed::factory()->create(['url' => 'http://93.184.216.34/feed.xml']);

    FetchRssFeed::dispatchSync($feed);

    expect($feed->refresh()->last_error)->toBe('create.feeds.errors.not_a_feed');
    Http::assertSentCount(1);
});

test('the failure recorder backs off from the stored failure count', function () {
    $feed = RssFeed::factory()->create(['consecutive_failures' => 10]);

    RecordRssFeedFetch::failure($feed, 'create.feeds.errors.blocked_url');

    expect($feed->refresh()->next_fetch_at->equalTo(now()->addMinutes(1440)))->toBeTrue()
        ->and($feed->last_error)->toBe('create.feeds.errors.blocked_url');
});

test('the poll queues only due feeds on the rss-feeds queue', function () {
    Queue::fake();
    $due = RssFeed::factory()->create(['next_fetch_at' => now()->subMinute()]);
    $never = RssFeed::factory()->create(['next_fetch_at' => null]);
    RssFeed::factory()->create(['next_fetch_at' => now()->addHour()]);

    $this->artisan('rss-feeds:poll')->assertSuccessful();

    Queue::assertPushed(FetchRssFeed::class, 2);
    Queue::assertPushedOn('rss-feeds', FetchRssFeed::class);
    Queue::assertPushed(FetchRssFeed::class, fn (FetchRssFeed $job): bool => $job->feed->is($due) && $job->uniqueId() === $due->id);
    Queue::assertPushed(FetchRssFeed::class, fn (FetchRssFeed $job): bool => $job->feed->is($never));
});

test('a job whose feed was deleted is dropped', function () {
    Http::fake();
    $feed = RssFeed::factory()->create();
    $job = new FetchRssFeed($feed);
    $payload = serialize($job);

    $feed->delete();

    expect(fn () => unserialize($payload))->toThrow(ModelNotFoundException::class)
        ->and($job->deleteWhenMissingModels)->toBeTrue();
    Http::assertNothingSent();
});

test('the poll runs every five minutes on one server without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'rss-feeds:poll'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

test('an unexpected failure records unreachable and backs off', function () {
    $feed = RssFeed::factory()->create(['consecutive_failures' => 1]);

    (new FetchRssFeed($feed))->failed(new RuntimeException('boom'));

    $feed->refresh();

    expect($feed->last_error)->toBe('create.feeds.errors.unreachable')
        ->and($feed->consecutive_failures)->toBe(2)
        ->and($feed->next_fetch_at->equalTo(now()->addMinutes(960)))->toBeTrue();
});

test('a second poll does not queue a feed that is already queued', function () {
    Queue::fake();
    $feed = RssFeed::factory()->create(['next_fetch_at' => null]);

    $this->artisan('rss-feeds:poll')->assertSuccessful();
    $this->travel(11)->minutes();
    $this->artisan('rss-feeds:poll')->assertSuccessful();

    Queue::assertPushed(FetchRssFeed::class, 1);
    expect($feed->refresh()->next_fetch_at->greaterThan(now()))->toBeTrue();
});

test('failures stop counting at the smallint ceiling', function () {
    $feed = RssFeed::factory()->create(['consecutive_failures' => 32767]);

    RecordRssFeedFetch::failure($feed, 'create.feeds.errors.unreachable');

    expect($feed->refresh()->consecutive_failures)->toBe(32767);
});
