<?php

declare(strict_types=1);

namespace App\Observers;

use App\Actions\Analytics\ShowPostingStreak;
use App\Models\AnalyticsPublication;

/** Keeps the sidebar's posting streak in step with the publications Insights finds. */
class AnalyticsPublicationObserver
{
    public function saved(AnalyticsPublication $publication): void
    {
        if ($publication->wasRecentlyCreated || $publication->wasChanged('provider_published_at')) {
            ShowPostingStreak::forget($publication->workspace_id);
        }
    }

    public function deleted(AnalyticsPublication $publication): void
    {
        ShowPostingStreak::forget($publication->workspace_id);
    }
}
