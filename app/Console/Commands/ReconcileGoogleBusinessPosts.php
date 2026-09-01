<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Jobs\ReconcileGoogleBusinessPost;
use App\Models\PostPlatform;
use Illuminate\Console\Command;

class ReconcileGoogleBusinessPosts extends Command
{
    protected $signature = 'social:reconcile-google-business-posts';

    protected $description = 'Settle Google Business Profile posts still awaiting review';

    /**
     * Google clears review in minutes, so a target checked moments ago has
     * nothing new to say. Keeps a five-minute schedule from re-polling every
     * pending target on every tick.
     */
    private const RECHECK_AFTER_MINUTES = 5;

    public function handle(): int
    {
        PostPlatform::query()
            ->where('platform', Platform::GoogleBusiness)
            ->where('status', Status::PendingReview)
            ->whereNotNull('platform_post_id')
            ->where(function ($query): void {
                $query->whereNull('last_reconciled_at')
                    ->orWhere('last_reconciled_at', '<=', now()->subMinutes(self::RECHECK_AFTER_MINUTES));
            })
            ->each(fn (PostPlatform $postPlatform) => ReconcileGoogleBusinessPost::dispatch($postPlatform));

        return self::SUCCESS;
    }
}
