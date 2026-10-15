<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Enums\PostHog\ChangelogEvent;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use App\Services\PostHogService;

class RecordChangelogEntryView
{
    /**
     * Records that the user opened the entry and returns that view. Only the
     * first open creates the row, clears the user's sidebar card and is sent
     * to PostHog.
     */
    public static function execute(User $user, ChangelogEntry $entry): ChangelogEntryView
    {
        $view = ChangelogEntryView::firstOrCreate(
            ['changelog_entry_id' => $entry->id, 'user_id' => $user->id],
            ['viewed_at' => now()],
        );

        if (! $view->wasRecentlyCreated) {
            return $view;
        }

        ShowChangelogCard::forget($user);

        app(PostHogService::class)->capture(
            $user->id,
            ChangelogEvent::EntryViewed->value,
            [
                'slug' => $entry->slug,
                'title' => $entry->title,
                'published_on' => $entry->published_on->toDateString(),
            ],
            $user->account,
        );

        return $view;
    }
}
