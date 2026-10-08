<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\PublishStatus;
use App\Jobs\VerifyUpcomingPostConnections;
use App\Models\Post;
use Illuminate\Console\Command;

class CheckUpcomingPostConnections extends Command
{
    protected $signature = 'social:check-upcoming-connections';

    protected $description = 'Proactively verify social connections for posts scheduled within the next hour';

    public function handle(): void
    {
        $workspaceIds = Post::query()
            ->scheduled()
            ->whereBetween('scheduled_at', [now(), now()->addHour()])
            ->where('publish_status', PublishStatus::Pending)
            ->whereHas('socialAccount')
            ->where(function ($query) {
                $query->whereNull('connection_warning_sent_at')
                    ->orWhere('connection_warning_sent_at', '<', now()->subDay());
            })
            ->distinct()
            ->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            VerifyUpcomingPostConnections::dispatch($workspaceId);
        }
    }
}
