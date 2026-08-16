<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class FindWorkspaceAsset
{
    public static function execute(Workspace $workspace, string $assetId, bool $lockForUpdate = false): ?Media
    {
        return Media::query()
            ->where('mediable_type', Relation::getMorphAlias(Workspace::class))
            ->where('mediable_id', $workspace->id)
            ->where('collection', 'assets')
            ->whereKey($assetId)
            ->when($lockForUpdate, fn (Builder $query) => $query->lockForUpdate())
            ->first();
    }
}
