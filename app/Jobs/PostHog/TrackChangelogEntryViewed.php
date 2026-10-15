<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\PostHog\ChangelogEvent;
use App\Models\ChangelogEntryView;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackChangelogEntryViewed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $viewId)
    {
        $this->onQueue('posthog');
    }

    public function handle(PostHogService $postHog): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        $view = ChangelogEntryView::query()
            ->with(['entry', 'user.account.plan'])
            ->find($this->viewId);

        if (blank($view?->entry) || blank($view->user)) {
            return;
        }

        $postHog->capture(
            (string) $view->user_id,
            ChangelogEvent::EntryViewed->value,
            [
                'slug' => $view->entry->slug,
                'title' => $view->entry->title,
                'published_on' => $view->entry->published_on->toDateString(),
            ],
            $view->user->account,
        );
    }
}
