<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Jobs\Media\DeleteMediaFiles;
use App\Models\Media;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Removes owned `medias` rows inside the caller's transaction and deletes
 * their files with a queued job only after it commits. Every owner delete path
 * goes through here. Direct deleters that remain: `PurgeUserAccess` (avatars)
 * and `HasMedia::clearMediaCollection` (logo/avatar replacement).
 */
class DeleteOwnedMedia
{
    public const int CHUNK = 500;

    /** @param list<string> $postIds */
    public static function forPosts(array $postIds): void
    {
        if ($postIds === []) {
            return;
        }

        self::purge(Media::query()->whereIn('post_id', $postIds));
    }

    /** @param list<string> $ideaIds */
    public static function forIdeas(array $ideaIds): void
    {
        if ($ideaIds === []) {
            return;
        }

        self::purge(Media::query()->whereIn('idea_id', $ideaIds));
    }

    /** @param list<string> $itemIds */
    public static function forFeedItems(array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        self::purge(Media::query()->whereIn('rss_feed_item_id', $itemIds));
    }

    public static function forWorkspace(Workspace $workspace): void
    {
        self::purge(Media::query()->where(fn (Builder $query) => $query
            ->where('workspace_id', $workspace->id)
            ->orWhere(fn (Builder $morph) => $morph
                ->where('mediable_type', $workspace->getMorphClass())
                ->where('mediable_id', $workspace->id))));
    }

    /**
     * `$scope` narrows the locked read, so a row that stopped matching since
     * the caller picked its ids (adopted by a post, say) is left alone.
     *
     * @param  list<string>  $mediaIds
     * @param  (Closure(Builder<Media>): void)|null  $scope
     */
    public static function forRows(array $mediaIds, ?Closure $scope = null): int
    {
        if ($mediaIds === []) {
            return 0;
        }

        return self::purge(Media::query()->whereKey($mediaIds)->when($scope !== null, fn (Builder $query) => $scope($query)));
    }

    /**
     * Rows are read with their paths under a row lock, in id order, and only
     * those ids are deleted: a row committed after the read survives (and the
     * owner's restrict foreign key then fails) instead of losing its file.
     *
     * @param  Builder<Media>  $query
     * @return int rows deleted
     */
    private static function purge(Builder $query): int
    {
        $deleted = 0;

        $query->select(['id', 'path'])
            ->lockForUpdate()
            ->chunkById(self::CHUNK, function (Collection $rows) use (&$deleted): void {
                $deleted += $rows->count();

                Media::query()->whereKey($rows->modelKeys())->delete();

                DeleteMediaFiles::dispatch($rows->pluck('path')->unique()->values()->all())->afterCommit();
            });

        return $deleted;
    }
}
