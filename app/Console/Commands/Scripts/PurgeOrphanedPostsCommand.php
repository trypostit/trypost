<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Post\DeleteChannelPosts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('posts:purge-orphaned {--workspace= : Only purge this workspace UUID}')]
#[Description('Delete posts whose channel was disconnected before disconnecting deleted them, and their media')]
class PurgeOrphanedPostsCommand extends Command
{
    public function handle(): int
    {
        $workspaceId = $this->option('workspace');

        if (filled($workspaceId) && ! Str::isUuid($workspaceId)) {
            $this->error('The --workspace option must be a workspace UUID.');

            return self::FAILURE;
        }

        $result = DeleteChannelPosts::orphaned(filled($workspaceId) ? $workspaceId : null);

        $this->info("{$result['deleted_posts']} orphaned post(s) deleted, {$result['detached_targets']} orphaned target(s) removed from posts on other channels.");

        return self::SUCCESS;
    }
}
