<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * The entry the sidebar card announces: the newest one dated since the user
 * signed up, only while the user has not opened it. Older unopened entries
 * never take its place; they stay in the "What's New" list.
 */
class ShowChangelogCard
{
    public static function execute(User $user): ?ChangelogEntry
    {
        $latest = ChangelogEntry::query()
            ->announcedTo($user)
            ->withSeenBy($user)
            ->latestFirst()
            ->first();

        return $latest?->seen ? null : $latest;
    }

    /**
     * Cached for 10 minutes so changing pages does not query it. The cache
     * holds the raw attributes, never the model: the cache store refuses to
     * unserialize objects (`cache.serializable_classes`).
     */
    public static function cached(User $user): ?ChangelogEntry
    {
        $attributes = Cache::remember(
            self::cacheKey($user),
            now()->addMinutes(10),
            fn (): array => self::execute($user)?->getAttributes() ?? [],
        );

        return filled($attributes) ? (new ChangelogEntry)->newFromBuilder($attributes) : null;
    }

    public static function forget(User $user): void
    {
        Cache::forget(self::cacheKey($user));
    }

    /**
     * Drops every user's cached card at once, after a sync changed the entries.
     */
    public static function forgetAll(): void
    {
        Cache::add(self::versionKey(), 0);
        Cache::increment(self::versionKey());
    }

    private static function cacheKey(User $user): string
    {
        $version = Cache::get(self::versionKey(), 0);

        return "changelog-card.{$version}.{$user->id}";
    }

    private static function versionKey(): string
    {
        return 'changelog-card:version';
    }
}
