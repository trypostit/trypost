<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Jobs\ReconcileGoogleBusinessProfilePost;
use App\Models\PostPlatform;
use Illuminate\Console\Command;

class ReconcileGoogleBusinessProfilePosts extends Command
{
    protected $signature = 'google-business-profile:reconcile-posts';

    protected $description = 'Reconcile submitted Google Business Profile posts with their live Google state';

    public function handle(): void
    {
        PostPlatform::query()
            ->where('platform', Platform::GoogleBusinessProfile)
            ->whereIn('status', [Status::Submitted, Status::PendingReview])
            ->whereNotNull('platform_post_id')
            ->oldest('last_reconciled_at')
            ->chunkById(100, function ($postPlatforms): void {
                foreach ($postPlatforms as $postPlatform) {
                    ReconcileGoogleBusinessProfilePost::dispatch($postPlatform);
                }
            });
    }
}
