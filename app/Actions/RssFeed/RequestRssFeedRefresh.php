<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Jobs\RssFeed\FetchRssFeed;
use App\Models\RssFeed;
use App\Models\RssFeedCollection;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class RequestRssFeedRefresh
{
    public static function execute(Workspace $workspace, ?RssFeed $feed = null, ?RssFeedCollection $collection = null): int
    {
        $cooldownEnds = now()->subMinutes((int) config('trypost.rss_feeds.refresh_cooldown_minutes'));

        $feeds = $workspace->rssFeeds()
            ->when($feed !== null, fn (Builder $query) => $query->whereKey($feed->id))
            ->when($collection !== null, fn (Builder $query) => $query->where('rss_feed_collection_id', $collection->id))
            ->where(fn (Builder $query) => $query->whereNull('last_fetched_at')->orWhere('last_fetched_at', '<=', $cooldownEnds))
            ->get();

        if ($feeds->isEmpty()) {
            return 0;
        }

        RssFeed::query()->whereKey($feeds->modelKeys())->update(['refresh_requested_at' => now()]);

        $feeds->each(fn (RssFeed $rssFeed) => FetchRssFeed::dispatch($rssFeed)->afterCommit());

        return $feeds->count();
    }
}
