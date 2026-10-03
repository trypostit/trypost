<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\Media\DeleteOwnedMedia;
use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
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
        PostPlatform::query()
            ->where('platform', Platform::GoogleBusiness)
            ->whereIn('post_id', $workspace->posts()->select('id'))
            ->pluck('id')
            ->each(fn (string $id) => app(GoogleBusinessDerivativeCleaner::class)->cleanup($id));

        DeleteOwnedMedia::forWorkspace($workspace);
        $workspace->delete();
    }
}
