<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Enums\Changelog\Reaction;
use App\Models\ChangelogEntry;
use App\Models\User;

class ReactToChangelogEntry
{
    /**
     * Stores the user's reaction on their view of the entry (null removes it).
     * ChangelogEntryViewObserver tracks each change.
     */
    public static function execute(User $user, ChangelogEntry $entry, ?Reaction $reaction): void
    {
        $view = RecordChangelogEntryView::execute($user, $entry);

        $view->update(['reaction' => $reaction]);
    }
}
