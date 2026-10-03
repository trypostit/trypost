<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Media a save in this workspace may use: a row owned by a post, idea or feed
 * item, or a temporary upload.
 */
class ResolveWorkspaceMedia
{
    /**
     * @param  list<string>  $ids
     * @return Collection<string, Media>
     */
    public static function execute(Workspace $workspace, array $ids, bool $lockForUpdate = false): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return self::query($workspace, $lockForUpdate)
            ->whereIn('id', array_values(array_unique($ids)))
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  list<string>  $tokens
     * @return Collection<string, Media>
     */
    public static function byUploadTokens(Workspace $workspace, array $tokens, bool $lockForUpdate = false): Collection
    {
        if ($tokens === []) {
            return collect();
        }

        return self::query($workspace, $lockForUpdate)
            ->where('collection', Media::COLLECTION_UPLOADS)
            ->whereIn('upload_token', array_values(array_unique($tokens)))
            ->get()
            ->keyBy('upload_token');
    }

    /**
     * @return Builder<Media>
     */
    private static function query(Workspace $workspace, bool $lockForUpdate): Builder
    {
        return Media::query()
            ->where('workspace_id', $workspace->id)
            ->where(fn (Builder $query) => $query
                ->whereNotNull('post_id')
                ->orWhereNotNull('idea_id')
                ->orWhereNotNull('rss_feed_item_id')
                ->orWhere('collection', Media::COLLECTION_UPLOADS))
            ->orderBy('id')
            ->when($lockForUpdate, fn (Builder $query) => $query->lockForUpdate());
    }
}
