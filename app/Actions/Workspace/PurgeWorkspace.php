<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\Media\DeleteWorkspaceMedia;
use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;

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
        PostPlatform::query()
            ->where('platform', Platform::GoogleBusiness)
            ->whereIn('post_id', $workspace->posts()->select('id'))
            ->pluck('id')
            ->each(fn (string $id) => app(GoogleBusinessDerivativeCleaner::class)->cleanup($id));

        $mediaPaths = DeleteWorkspaceMedia::purgeRecords($workspace);
        $workspace->delete();

        return $mediaPaths;
    }
}
