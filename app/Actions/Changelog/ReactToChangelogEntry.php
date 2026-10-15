<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Enums\Changelog\Reaction;
use App\Enums\PostHog\ChangelogEvent;
use App\Models\ChangelogEntry;
use App\Models\ChangelogEntryView;
use App\Models\User;
use App\Services\PostHogService;

class ReactToChangelogEntry
{
    /**
     * Stores the user's reaction on their view of the entry (null removes it)
     * and sends each change to PostHog.
     */
    public static function execute(User $user, ChangelogEntry $entry, ?Reaction $reaction): void
    {
        RecordChangelogEntryView::execute($user, $entry);

        $view = ChangelogEntryView::query()
            ->where('changelog_entry_id', $entry->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($view->reaction === $reaction) {
            return;
        }

        $view->update(['reaction' => $reaction]);

        app(PostHogService::class)->capture(
            $user->id,
            ChangelogEvent::EntryReacted->value,
            [
                'slug' => $entry->slug,
                'title' => $entry->title,
                'published_on' => $entry->published_on->toDateString(),
                'reaction' => $reaction?->value,
            ],
            $user->account,
        );
    }
}
