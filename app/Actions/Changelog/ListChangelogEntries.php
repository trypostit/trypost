<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class ListChangelogEntries
{
    /**
     * The "What's New" modal loads ten entries per page (owner decision
     * 2026-10-14, an exception to `app.pagination.default`).
     */
    public const PAGE_SIZE = 10;

    public const PAGE_NAME = 'changelog_page';

    /**
     * @return LengthAwarePaginator<int, ChangelogEntry>
     */
    public static function execute(User $user): LengthAwarePaginator
    {
        return ChangelogEntry::query()
            ->published()
            ->withSeenBy($user)
            ->withReactionBy($user)
            ->latestFirst()
            ->paginate(self::PAGE_SIZE, pageName: self::PAGE_NAME);
    }

    /**
     * The entry the sidebar card announces: the newest one dated since the
     * user signed up, only while the user has not opened it. Older unopened
     * entries never take its place; they stay in the list.
     */
    public static function latestForCard(User $user): ?ChangelogEntry
    {
        $latest = ChangelogEntry::query()
            ->announcedTo($user)
            ->withSeenBy($user)
            ->latestFirst()
            ->first();

        return $latest?->seen ? null : $latest;
    }
}
