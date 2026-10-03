<?php

declare(strict_types=1);

namespace App\Console\Commands\RssFeed;

use App\Jobs\RssFeed\FetchRssFeed;
use App\Models\RssFeed;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class PollRssFeeds extends Command
{
    private const int DISPATCH_LEASE_MINUTES = 30;

    protected $signature = 'rss-feeds:poll';

    protected $description = 'Queue a refresh for every RSS feed that is due';

    public function handle(): int
    {
        RssFeed::query()
            ->due()
            ->chunkById(200, function (Collection $feeds): void {
                RssFeed::query()
                    ->whereKey($feeds->modelKeys())
                    ->update(['next_fetch_at' => now()->addMinutes(self::DISPATCH_LEASE_MINUTES)]);

                foreach ($feeds as $feed) {
                    FetchRssFeed::dispatch($feed);
                }
            });

        return self::SUCCESS;
    }
}
