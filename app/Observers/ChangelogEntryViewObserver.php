<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\PostHog\ChangelogEvent;
use App\Jobs\PostHog\TrackChangelogEntryEvent;
use App\Models\ChangelogEntryView;
use App\Services\PostHogService;

class ChangelogEntryViewObserver
{
    public function created(ChangelogEntryView $view): void
    {
        if (PostHogService::isEnabled()) {
            TrackChangelogEntryEvent::dispatch($view->id, ChangelogEvent::EntryViewed);
        }
    }

    public function updated(ChangelogEntryView $view): void
    {
        if (PostHogService::isEnabled() && $view->wasChanged('reaction')) {
            TrackChangelogEntryEvent::dispatch($view->id, ChangelogEvent::EntryReacted, $view->reaction);
        }
    }
}
