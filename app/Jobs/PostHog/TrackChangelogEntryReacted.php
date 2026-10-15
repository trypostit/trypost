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

/**
 * Carries the reaction chosen at the click, so a later change does not
 * rewrite what this event reports.
 */
class TrackChangelogEntryReacted implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $viewId, public ?string $reaction)
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
            ChangelogEvent::EntryReacted->value,
            [
                'slug' => $view->entry->slug,
                'title' => $view->entry->title,
                'published_on' => $view->entry->published_on->toDateString(),
                'reaction' => $this->reaction,
            ],
            $view->user->account,
        );
    }
}
