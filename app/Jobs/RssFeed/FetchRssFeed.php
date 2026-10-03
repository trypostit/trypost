<?php

declare(strict_types=1);

namespace App\Jobs\RssFeed;

use App\Actions\RssFeed\RecordRssFeedFetch;
use App\Actions\RssFeed\SyncRssFeedItems;
use App\Exceptions\RssFeed\InvalidRssFeedException;
use App\Exceptions\RssFeed\RssFeedFetchException;
use App\Models\RssFeed;
use App\Services\RssFeed\RssFeedFetcher;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class FetchRssFeed implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public RssFeed $feed)
    {
        $this->onQueue('rss-feeds');
    }

    public function uniqueId(): string
    {
        return $this->feed->id;
    }

    public function handle(RssFeedFetcher $fetcher): void
    {
        try {
            $fetched = $fetcher->fetch($this->feed->url);
        } catch (RssFeedFetchException $exception) {
            RecordRssFeedFetch::failure($this->feed, $exception->errorKey);

            return;
        } catch (InvalidRssFeedException) {
            RecordRssFeedFetch::failure($this->feed, 'create.feeds.errors.not_a_feed');

            return;
        }

        SyncRssFeedItems::execute($this->feed, $fetched->feed);
        RecordRssFeedFetch::success($this->feed);
    }

    public function failed(?Throwable $exception): void
    {
        $feed = RssFeed::query()->find($this->feed->id);

        if ($feed !== null) {
            RecordRssFeedFetch::failure($feed, 'create.feeds.errors.unreachable');
        }
    }
}
