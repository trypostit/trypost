<?php

declare(strict_types=1);

namespace App\Console\Commands\Scripts;

use App\Actions\Media\DeleteOwnedMedia;
use App\Actions\Post\PruneExpiredPostHistory;
use App\Models\Post;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('posts:purge-imported {--workspace= : Only purge this workspace UUID}')]
#[Description('Delete posts imported from the networks, and their media; analytics publications become unlinked')]
class PurgeImportedPostsCommand extends Command
{
    public function handle(): int
    {
        $workspaceId = $this->option('workspace');

        if (filled($workspaceId) && ! Str::isUuid($workspaceId)) {
            $this->error('The --workspace option must be a workspace UUID.');

            return self::FAILURE;
        }

        $purged = 0;

        Post::query()
            ->imported()
            ->when($workspaceId, fn (Builder $query, string $workspaceId): Builder => $query->where('workspace_id', $workspaceId))
            ->select('id')
            ->chunkById(PruneExpiredPostHistory::CHUNK, function (Collection $posts) use (&$purged): void {
                $ids = $posts->modelKeys();

                DB::transaction(function () use ($ids): void {
                    DeleteOwnedMedia::forPosts($ids);
                    Post::query()->whereKey($ids)->delete();
                });

                $purged += count($ids);
            });

        $this->info("{$purged} imported post(s) deleted.");

        return self::SUCCESS;
    }
}
