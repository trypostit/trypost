<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Enums\PostHog\ChangelogEvent;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Database\UniqueConstraintViolationException;

class RecordChangelogEntryView
{
    /**
     * Records that the user opened the entry. Only the first open is stored
     * and sent to PostHog; returns whether this call was that first open.
     */
    public static function execute(User $user, ChangelogEntry $entry): bool
    {
        try {
            $view = ChangelogEntryView::firstOrCreate(
                ['changelog_entry_id' => $entry->id, 'user_id' => $user->id],
                ['viewed_at' => now()],
            );
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if (! $view->wasRecentlyCreated) {
            return false;
        }

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

        return true;
    }
}
