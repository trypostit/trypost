<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Exceptions\RssFeed\RssFeedFetchException;
use App\Models\RssFeed;
use App\Models\Workspace;
use App\Services\Http\SafeHttpFetcher;
use App\Services\RssFeed\RssFeedFetcher;
use App\Support\RssFeedUrl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddRssFeed
{
    public static function execute(Workspace $workspace, string $url, ?string $collectionId = null): RssFeed
    {
        $limit = (int) config('trypost.rss_feeds.max_feeds_per_workspace');

        if ($workspace->rssFeeds()->count() >= $limit) {
            throw self::limitReached($limit);
        }

        $normalized = app(SafeHttpFetcher::class)->normalizeUrl($url);

        self::assertNotAdded($workspace, $normalized);

        try {
            $fetched = app(RssFeedFetcher::class)->fetch($normalized, allowDiscovery: true);
        } catch (RssFeedFetchException $exception) {
            throw ValidationException::withMessages(['url' => __($exception->errorKey)]);
        }

        self::assertNotAdded($workspace, $fetched->url);

        try {
            return DB::transaction(function () use ($workspace, $fetched, $limit, $collectionId): RssFeed {
                Workspace::query()->lockForUpdate()->find($workspace->id);

                if ($workspace->rssFeeds()->count() >= $limit) {
                    throw self::limitReached($limit);
                }

                $feed = $workspace->rssFeeds()->create([
                    'url' => $fetched->url,
                    'url_hash' => RssFeedUrl::hash($fetched->url),
                    'format' => $fetched->feed->format,
                    'title' => $fetched->feed->title,
                    'site_url' => $fetched->feed->siteUrl,
                    'rss_feed_collection_id' => $collectionId,
                ]);

                SyncRssFeedItems::execute($feed, $fetched->feed);
                RecordRssFeedFetch::success($feed);

                return $feed;
            });
        } catch (UniqueConstraintViolationException) {
            throw self::alreadyAdded();
        }
    }

    private static function assertNotAdded(Workspace $workspace, string $url): void
    {
        if ($workspace->rssFeeds()->where('url_hash', RssFeedUrl::hash($url))->exists()) {
            throw self::alreadyAdded();
        }
    }

    private static function limitReached(int $limit): ValidationException
    {
        return ValidationException::withMessages(['url' => __('create.feeds.errors.limit_reached', ['max' => $limit])]);
    }

    private static function alreadyAdded(): ValidationException
    {
        return ValidationException::withMessages(['url' => __('create.feeds.errors.already_added')]);
    }
}
