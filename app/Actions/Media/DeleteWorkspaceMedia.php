<?php

declare(strict_types=1);

namespace App\Actions\Media;

use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\Relation;

class DeleteWorkspaceMedia
{
    /**
     * Delete workspace media rows and return their storage paths.
     *
     * Intended for use inside a DB transaction / lock. Call
     * DeleteOrphanedMediaFiles with the returned paths after commit so
     * filesystem I/O stays outside the lock.
     *
     * @return list<string>
     */
    public static function purgeRecords(Workspace $workspace): array
    {
        $query = Media::query()
            ->where('mediable_type', Relation::getMorphAlias(Workspace::class))
            ->where('mediable_id', $workspace->id);

        /** @var list<string> $paths */
        $paths = $query->pluck('path')->all();
        $query->delete();

        return $paths;
    }
}
