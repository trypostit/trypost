<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Models\RssFeed;

class RecordRssFeedFetch
{
    public static function success(RssFeed $feed): void
    {
        $now = now();

        $feed->update([
            'last_fetched_at' => $now,
            'last_succeeded_at' => $now,
            'consecutive_failures' => 0,
            'last_error' => null,
            'next_fetch_at' => $now->addMinutes((int) config('trypost.rss_feeds.poll_interval_minutes')),
        ]);
    }

    public static function failure(RssFeed $feed, string $errorKey): void
    {
        $now = now();
        $failures = $feed->consecutive_failures + 1;
        $delay = min(
            (int) config('trypost.rss_feeds.poll_interval_minutes') * 2 ** min($failures - 1, 16),
            (int) config('trypost.rss_feeds.max_backoff_minutes'),
        );

        $feed->update([
            'last_fetched_at' => $now,
            'consecutive_failures' => min($failures, 32767),
            'last_error' => $errorKey,
            'next_fetch_at' => $now->addMinutes($delay),
        ]);
    }
}
