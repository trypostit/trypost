<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Jobs\VerifyUpcomingPostConnections;
use App\Models\PostPlatform;
use Illuminate\Console\Command;

class CheckUpcomingPostConnections extends Command
{
    protected $signature = 'social:check-upcoming-connections';

    protected $description = 'Proactively verify social connections for posts scheduled within the next hour';

    public function handle(): void
    {
        $workspaceIds = PostPlatform::query()
            ->where('post_platforms.status', PostPlatformStatus::Pending)
            ->enabled()
            // Mirrors VerifyUpcomingPostConnections::atRiskPostPlatforms() —
            // a paused account can't be the reason to dispatch a job for its
            // workspace, since the job itself will skip it too. whereHas()
            // already excludes a null social_account_id (nothing to join to).
            ->whereHas('socialAccount', fn ($query) => $query->where('is_active', true))
            ->where(function ($query) {
                $query->whereNull('post_platforms.connection_warning_sent_at')
                    ->orWhere('post_platforms.connection_warning_sent_at', '<', now()->subDay());
            })
            ->join('posts', 'posts.id', '=', 'post_platforms.post_id')
            ->where('posts.status', PostStatus::Scheduled)
            ->whereBetween('posts.scheduled_at', [now(), now()->addHour()])
            ->distinct()
            ->pluck('posts.workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            VerifyUpcomingPostConnections::dispatch($workspaceId);
        }

        $this->info("Dispatched {$workspaceIds->count()} upcoming-post connection checks.");
    }
}
