<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\Media\DeleteOwnedMedia;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Social\GoogleBusinessDerivativeCleaner;

class PurgeWorkspace
{
    /**
     * Delete every media row of the workspace (files go after commit) and the
     * workspace itself. Related posts/accounts/labels/etc. cascade via FK.
     */
    public static function execute(Workspace $workspace): void
    {
        $workspace->posts()
            ->where('platform', Platform::GoogleBusiness)
            ->select(['id', 'legacy_target_id'])
            ->each(fn (Post $post) => app(GoogleBusinessDerivativeCleaner::class)->cleanup($post));

        DeleteOwnedMedia::forWorkspace($workspace);
        $workspace->delete();
    }
}
