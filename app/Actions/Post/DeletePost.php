<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\DeleteOwnedMedia;
use App\Enums\Post\Origin;
use App\Enums\SocialAccount\Platform;
use App\Events\PostDeleted;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\DB;

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
        $isImported = $post->origin === Origin::Network;

        DB::transaction(function () use ($post, $postId): void {
            Post::query()->whereKey($postId)->lockForUpdate()->first();

            AnalyticsPublication::query()
                ->whereIn('post_platform_id', $post->postPlatforms()->pluck('id')->all())
                ->update(['post_dismissed_at' => now()]);

            DeleteOwnedMedia::forPosts([$postId]);
            $post->delete();
        });

        if (! $isImported) {
            PostDeleted::dispatch($postId, $workspaceId);
        }
    }
}
