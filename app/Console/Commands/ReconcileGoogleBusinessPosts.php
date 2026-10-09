<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\ReconcileGoogleBusinessPost;
use App\Models\Post;
use Illuminate\Console\Command;

class ReconcileGoogleBusinessPosts extends Command
{
    protected $signature = 'social:reconcile-google-business-posts';

    protected $description = 'Settle Google Business Profile posts still awaiting review';

    /**
     * Google clears review in minutes, so a post checked moments ago has
     * nothing new to say. Keeps a five-minute schedule from re-polling every
     * pending post on every tick.
     */
    private const RECHECK_AFTER_MINUTES = 5;

    public function handle(): int
    {
        Post::query()
            ->where('platform', Platform::GoogleBusiness)
            ->where('publish_status', PublishStatus::PendingReview)
            ->whereNotNull('platform_post_id')
            ->where(function ($query): void {
                $query->whereNull('last_reconciled_at')
                    ->orWhere('last_reconciled_at', '<=', now()->subMinutes(self::RECHECK_AFTER_MINUTES));
            })
            ->each(fn (Post $post) => ReconcileGoogleBusinessPost::dispatch($post));

        return self::SUCCESS;
    }
}
