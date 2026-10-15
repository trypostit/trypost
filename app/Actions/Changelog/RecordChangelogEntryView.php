<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;

class RecordChangelogEntryView
{
    /**
     * Records that the user opened the entry and returns that view. Only the
     * first open creates the row (tracked by ChangelogEntryViewObserver) and
     * clears the user's sidebar card.
     */
    public static function execute(User $user, ChangelogEntry $entry): ChangelogEntryView
    {
        $view = ChangelogEntryView::firstOrCreate(
            ['changelog_entry_id' => $entry->id, 'user_id' => $user->id],
            ['viewed_at' => now()],
        );

        if ($view->wasRecentlyCreated) {
            ShowChangelogCard::forget($user);
        }

        return $view;
    }
}
