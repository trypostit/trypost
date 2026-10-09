<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\DeleteOwnedMedia;
use App\Enums\Post\Origin;
use App\Enums\SocialAccount\Platform;
use App\Events\PostDeleted;
use App\Models\AnalyticsPublication;
use App\Models\Post;
use App\Support\PostStatusRules;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeletePost
{
    /**
     * @throws ValidationException
     */
    public static function execute(Post $post, bool $respectStatus = false): void
    {
        $postId = $post->id;
        $workspaceId = $post->workspace_id;
        $isImported = $post->origin === Origin::Network;

        $deleted = DB::transaction(function () use ($post, $postId, $respectStatus): bool {
            $locked = Post::query()->whereKey($postId)->lockForUpdate()->first();

            if ($locked === null) {
                return false;
            }

            if ($respectStatus && PostStatusRules::blocksDeletion($locked)) {
                throw ValidationException::withMessages(['post' => __('posts.flash.cannot_delete_published')]);
            }

            AnalyticsPublication::query()
                ->where('post_id', $post->id)
                ->update(['post_dismissed_at' => now()]);

            DeleteOwnedMedia::forPosts([$postId]);
            $post->delete();

            if ($post->platform === Platform::GoogleBusiness) {
                DB::afterCommit(fn () => app(GoogleBusinessDerivativeCleaner::class)->cleanup($post));
            }

            return true;
        });

        if ($deleted && ! $isImported) {
            PostDeleted::dispatch($postId, $workspaceId);
        }
    }
}
