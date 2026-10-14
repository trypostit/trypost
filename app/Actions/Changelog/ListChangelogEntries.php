<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Models\ChangelogEntry;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class ListChangelogEntries
{
    /**
     * The "What's New" modal loads five entries per page (owner decision
     * 2026-10-14, an exception to `app.pagination.default`).
     */
    public const PAGE_SIZE = 5;

    public const PAGE_NAME = 'changelog_page';

    /**
     * @return LengthAwarePaginator<int, ChangelogEntry>
     */
    public static function execute(User $user): LengthAwarePaginator
    {
        return ChangelogEntry::query()
            ->visibleTo($user)
            ->withSeenBy($user)
            ->latestFirst()
            ->paginate(self::PAGE_SIZE, pageName: self::PAGE_NAME);
    }

    public static function latestUnseen(User $user): ?ChangelogEntry
    {
        return ChangelogEntry::query()
            ->visibleTo($user)
            ->unseenBy($user)
            ->latestFirst()
            ->first();
    }
}
