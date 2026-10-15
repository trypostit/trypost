<?php

declare(strict_types=1);

namespace App\Observers;

use App\Jobs\PostHog\TrackChangelogEntryReacted;
use App\Jobs\PostHog\TrackChangelogEntryViewed;
use App\Models\ChangelogEntryView;
use App\Services\PostHogService;

class ChangelogEntryViewObserver
{
    public function created(ChangelogEntryView $view): void
    {
        if (PostHogService::isEnabled()) {
            TrackChangelogEntryViewed::dispatch((string) $view->id);
        }
    }

    public function updated(ChangelogEntryView $view): void
    {
        if (PostHogService::isEnabled() && $view->wasChanged('reaction')) {
            TrackChangelogEntryReacted::dispatch((string) $view->id, $view->reaction?->value);
        }
    }
}
