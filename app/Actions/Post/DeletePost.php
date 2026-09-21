<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\SocialAccount\Platform;
use App\Events\PostDeleted;
use App\Models\Post;
use App\Support\Social\GoogleBusinessDerivativeCleaner;

class DeletePost
{
    public static function execute(Post $post): void
    {
        $post->postPlatforms()
            ->where('platform', Platform::GoogleBusiness)
            ->pluck('id')
            ->each(fn (string $id) => app(GoogleBusinessDerivativeCleaner::class)->cleanup($id));

        $postId = $post->id;
        $workspaceId = $post->workspace_id;

        $post->delete();

        PostDeleted::dispatch($postId, $workspaceId);
    }
}
