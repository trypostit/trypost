<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Enums\Changelog\Reaction;
use App\Enums\PostHog\ChangelogEvent;
use App\Models\ChangelogEntryView;
use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A reaction event carries the reaction picked at the click, so a later
 * change does not rewrite what it reports.
 */
class TrackChangelogEntryEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(
        public string $viewId,
        public ChangelogEvent $event,
        public ?Reaction $reaction = null,
    ) {
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

        if (blank($view)) {
            return;
        }

        $postHog->capture(
            $view->user_id,
            $this->event->value,
            [
                'slug' => $view->entry->slug,
                'title' => $view->entry->title,
                'published_on' => $view->entry->published_on->toDateString(),
                ...($this->event === ChangelogEvent::EntryReacted ? ['reaction' => $this->reaction?->value] : []),
            ],
            $view->user->account,
        );
    }
}
