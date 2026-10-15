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
     * Cached for 10 minutes so changing pages does not query it; recording a
     * view clears it through forget().
     */
    public static function cached(User $user): ?ChangelogEntry
    {
        return data_get(
            Cache::remember(self::cacheKey($user), now()->addMinutes(10), fn (): array => ['entry' => self::execute($user)]),
            'entry',
        );
    }

    public static function forget(User $user): void
    {
        Cache::forget(self::cacheKey($user));
    }

    private static function cacheKey(User $user): string
    {
        return "changelog-card.{$user->id}";
    }
}
