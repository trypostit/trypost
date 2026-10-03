<?php

declare(strict_types=1);

namespace App\Jobs\RssFeed;

use App\Models\RssFeedItem;
use App\Services\Http\SafeHttpFetcher;
use App\Services\RssFeed\OgImageExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchRssFeedItemImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public RssFeedItem $item)
    {
        $this->onQueue('rss-feeds');
    }

    public function handle(SafeHttpFetcher $http, OgImageExtractor $extractor): void
    {
        if ($this->item->image_url !== null || $this->item->image_checked_at !== null) {
            return;
        }

        $imageUrl = null;

        if ($this->item->url !== null) {
            $page = $http->tryFetchPrefix(
                $this->item->url,
                (int) config('trypost.rss_feeds.og_image_max_page_bytes'),
                now()->addSeconds((int) config('trypost.rss_feeds.fetch_budget_seconds')),
            );

            $imageUrl = $page === null ? null : $extractor->extract($page->body, $page->finalUrl);
        }

        RssFeedItem::query()
            ->whereKey($this->item->id)
            ->whereNull('image_url')
            ->update([
                'image_url' => $imageUrl,
                'image_checked_at' => now(),
            ]);
    }
}
