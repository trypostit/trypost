<?php

declare(strict_types=1);

namespace App\Actions\Idea;

use App\Actions\Media\DeleteOwnedMedia;
use App\Models\Idea;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class DeleteIdeas
{
    /**
     * Locks the ideas before their media rows, the same order the idea write
     * paths take, so a concurrent save cannot deadlock against a delete.
     *
     * @param  list<string>  $ideaIds
     */
    public static function execute(Workspace $workspace, array $ideaIds): int
    {
        return DB::transaction(function () use ($workspace, $ideaIds): int {
            $ids = Idea::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('id', $ideaIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return 0;
            }

            DeleteOwnedMedia::forIdeas($ids);

            return Idea::query()->whereKey($ids)->delete();
        });
    }
}
