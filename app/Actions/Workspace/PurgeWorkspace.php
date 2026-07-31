<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\Media\DeleteWorkspaceMedia;
use App\Models\Workspace;

class PurgeWorkspace
{
    /**
     * Delete workspace media rows and the workspace itself.
     * Related posts/accounts/labels/etc. cascade via FK.
     *
     * Returns media storage paths for DeleteOrphanedMediaFiles after commit.
     *
     * @return list<string>
     */
    public static function execute(Workspace $workspace): array
    {
        $mediaPaths = DeleteWorkspaceMedia::purgeRecords($workspace);
        $workspace->delete();

        return $mediaPaths;
    }
}
