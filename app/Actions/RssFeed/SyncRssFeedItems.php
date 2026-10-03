<?php

declare(strict_types=1);

namespace App\Actions\RssFeed;

use App\Actions\Media\DeleteOwnedMedia;
use App\Jobs\RssFeed\FetchRssFeedItemImage;
use App\Models\RssFeed;
use App\Models\RssFeedItem;
use App\Services\RssFeed\ParsedRssFeed;
use App\Services\RssFeed\ParsedRssFeedItem;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SyncRssFeedItems
{
    private const int UPSERT_CHUNK = 500;

    private const array UPDATED_COLUMNS = ['title', 'url', 'excerpt', 'author', 'updated_at'];

    public static function execute(RssFeed $feed, ParsedRssFeed $parsed): int
    {
        $now = now()->toImmutable()->startOfSecond();
        $stored = $feed->items()->get(['id', 'guid_hash', 'published_at'])->keyBy('guid_hash');

        $rows = collect($parsed->items)
            ->map(fn (ParsedRssFeedItem $item): array => [
                'id' => (string) Str::uuid7(),
                'rss_feed_id' => $feed->id,
                'guid_hash' => RssFeedItem::hashGuid($item->guid ?? "{$item->title}|{$item->publishedAt?->toIso8601String()}"),
                'title' => $item->title,
                'url' => $item->url,
                'excerpt' => $item->excerpt,
                'image_url' => $item->imageUrl,
                'author' => $item->author,
                'published_at' => self::clamp($item->publishedAt, $now),
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->unique(fn (array $row): string => data_get($row, 'guid_hash'))
            ->keyBy(fn (array $row): string => data_get($row, 'guid_hash'));

        $kept = self::rankedHashes($stored, $rows);
        $rows = $rows->only($kept)->values();

        [$withImage, $withoutImage] = $rows->partition(fn (array $row): bool => data_get($row, 'image_url') !== null);

        $withImage->chunk(self::UPSERT_CHUNK)->each(fn (Collection $chunk) => RssFeedItem::query()
            ->upsert($chunk->values()->all(), ['rss_feed_id', 'guid_hash'], [...self::UPDATED_COLUMNS, 'image_url']));

        $withoutImage->chunk(self::UPSERT_CHUNK)->each(fn (Collection $chunk) => RssFeedItem::query()
            ->upsert($chunk->values()->all(), ['rss_feed_id', 'guid_hash'], self::UPDATED_COLUMNS));

        self::prune($feed);

        $feed->update([
            'title' => $parsed->title,
            'format' => $parsed->format,
            'site_url' => $parsed->siteUrl,
            'icon_url' => self::iconUrl($parsed->siteUrl ?? $feed->url),
        ]);

        $new = $rows->reject(fn (array $row): bool => $stored->has(data_get($row, 'guid_hash')));

        self::dispatchImageLookups($feed, $new, $now);

        return $new->count();
    }

    /**
     * The hashes prune() would keep: stored rows by their stored date, new
     * rows by the date they will be stored with, newest first, then id.
     *
     * @param  EloquentCollection<string, RssFeedItem>  $stored
     * @param  Collection<string, array<string, mixed>>  $rows
     * @return list<string>
     */
    private static function rankedHashes(EloquentCollection $stored, Collection $rows): array
    {
        $candidates = $stored
            ->map(fn (RssFeedItem $item): array => [
                'guid_hash' => $item->guid_hash,
                'timestamp' => $item->published_at->getTimestamp(),
                'id' => $item->id,
            ])
            ->toBase()
            ->merge($rows
                ->reject(fn (array $row): bool => $stored->has(data_get($row, 'guid_hash')))
                ->map(fn (array $row): array => [
                    'guid_hash' => data_get($row, 'guid_hash'),
                    'timestamp' => data_get($row, 'published_at')->getTimestamp(),
                    'id' => data_get($row, 'id'),
                ]));

        return $candidates
            ->sort(fn (array $a, array $b): int => [data_get($b, 'timestamp'), data_get($b, 'id')] <=> [data_get($a, 'timestamp'), data_get($a, 'id')])
            ->take((int) config('trypost.rss_feeds.max_items_per_feed'))
            ->pluck('guid_hash')
            ->values()
            ->all();
    }

    private static function clamp(?CarbonImmutable $publishedAt, CarbonImmutable $now): CarbonInterface
    {
        if ($publishedAt === null || $publishedAt->greaterThan($now) || $publishedAt->lessThan('1970-01-02 00:00:00')) {
            return $now;
        }

        return $publishedAt;
    }

    private static function prune(RssFeed $feed): void
    {
        $keep = $feed->items()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit((int) config('trypost.rss_feeds.max_items_per_feed'))
            ->pluck('id');

        DB::transaction(function () use ($feed, $keep): void {
            $pruned = $feed->items()
                ->whereNotIn('id', $keep)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($pruned === []) {
                return;
            }

            DeleteOwnedMedia::forFeedItems($pruned);
            RssFeedItem::query()->whereKey($pruned)->delete();
        });
    }

    private static function iconUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? "https://{$host}/favicon.ico" : null;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $new
     */
    private static function dispatchImageLookups(RssFeed $feed, Collection $new, CarbonImmutable $now): void
    {
        $oldest = $now->subDays((int) config('trypost.rss_feeds.og_image_max_age_days'));

        $hashes = $new
            ->filter(fn (array $row): bool => data_get($row, 'image_url') === null
                && data_get($row, 'url') !== null
                && data_get($row, 'published_at')->greaterThanOrEqualTo($oldest))
            ->sortByDesc(fn (array $row): int => data_get($row, 'published_at')->getTimestamp())
            ->take((int) config('trypost.rss_feeds.og_image_max_items_per_poll'))
            ->pluck('guid_hash');

        if ($hashes->isEmpty()) {
            return;
        }

        $feed->items()
            ->whereIn('guid_hash', $hashes)
            ->whereNull('image_url')
            ->whereNull('image_checked_at')
            ->get()
            ->each(fn (RssFeedItem $item) => FetchRssFeedItemImage::dispatch($item)->afterCommit());
    }
}
