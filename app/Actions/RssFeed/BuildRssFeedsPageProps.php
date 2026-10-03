<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Http\Resources\App\RssFeedCollectionResource;
use App\Http\Resources\App\RssFeedItemResource;
use App\Http\Resources\App\RssFeedResource;
use App\Models\RssFeed;
use App\Models\RssFeedCollection;
use App\Models\RssFeedItem;
use App\Models\Workspace;
use App\Support\RssFeedUrl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;

class BuildRssFeedsPageProps
{
    /**
     * @return array<string, mixed>
     */
    public static function execute(Request $request, Workspace $workspace, ?RssFeed $feed = null, ?RssFeedCollection $collection = null): array
    {
        $scoped = fn (): Builder => RssFeed::query()
            ->where('workspace_id', $workspace->id)
            ->when($feed !== null, fn (Builder $query) => $query->whereKey($feed->id))
            ->when($collection !== null, fn (Builder $query) => $query->where('rss_feed_collection_id', $collection->id));

        return [
            'scope' => self::scope($request, $feed, $collection),
            'feeds' => fn () => RssFeedResource::collection(
                $workspace->rssFeeds()->orderBy('created_at')->orderBy('id')->get()
            ),
            'collections' => fn () => RssFeedCollectionResource::collection(
                $workspace->rssFeedCollections()->withCount('feeds')->orderBy('id')->get()
            ),
            'items' => Inertia::scroll(fn () => RssFeedItemResource::collection(
                RssFeedItem::query()
                    ->whereIn('rss_feed_id', $scoped()->select('id'))
                    ->with('feed:id,title,custom_title,icon_url')
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->paginate((int) config('app.pagination.default'))
            )),
            'last_refreshed_at' => fn () => self::lastRefreshedAt($scoped()),
            'refreshing' => fn () => $scoped()
                ->whereNotNull('refresh_requested_at')
                ->where(fn (Builder $query) => $query
                    ->whereNull('last_fetched_at')
                    ->orWhereColumn('refresh_requested_at', '>', 'last_fetched_at'))
                ->exists(),
            'limits' => fn () => [
                'max_feeds' => (int) config('trypost.rss_feeds.max_feeds_per_workspace'),
                'feeds_count' => $workspace->rssFeeds()->count(),
            ],
            'directory' => Inertia::optional(fn () => self::directory($workspace)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function scope(Request $request, ?RssFeed $feed, ?RssFeedCollection $collection): array
    {
        if ($feed !== null) {
            return ['kind' => 'feed', 'feed' => RssFeedResource::make($feed)->resolve($request)];
        }

        if ($collection !== null) {
            return ['kind' => 'collection', 'collection' => RssFeedCollectionResource::make($collection->loadCount('feeds'))->resolve($request)];
        }

        return ['kind' => 'all'];
    }

    private static function lastRefreshedAt(Builder $query): ?string
    {
        $latest = $query->max('last_succeeded_at');

        return $latest === null ? null : Carbon::parse($latest)->toISOString();
    }

    /**
     * @return list<array{key: string, entries: list<array<string, mixed>>}>
     */
    private static function directory(Workspace $workspace): array
    {
        /** @var Collection<string, string> $subscribed */
        $subscribed = $workspace->rssFeeds()->pluck('id', 'url_hash');

        return collect((array) config('trypost.rss_feeds.directory'))
            ->map(fn (array $entries, string $key): array => [
                'key' => $key,
                'entries' => collect($entries)
                    ->map(function (array $entry) use ($subscribed): array {
                        $url = (string) data_get($entry, 'url');
                        $feedId = $subscribed->get(RssFeedUrl::hash($url));
                        $host = parse_url($url, PHP_URL_HOST);

                        return [
                            'name' => (string) data_get($entry, 'name'),
                            'url' => $url,
                            'icon_url' => is_string($host) ? "https://{$host}/favicon.ico" : null,
                            'subscribed' => $feedId !== null,
                            'feed_id' => $feedId,
                        ];
                    })
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();
    }
}
