<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Events\PostDeleted;
use App\Models\Post;
use App\Support\PostStatusRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeletePost
{
    public static function execute(Post $post): void
    {
        [$postId, $workspaceId] = DB::transaction(function () use ($post): array {
            $fresh = Post::query()->lockForUpdate()->findOrFail($post->getKey());

            if (PostStatusRules::blocksDeletion($fresh)) {
                throw ValidationException::withMessages([
                    'post' => PostStatusRules::deleteBlockedMessage(),
                ]);
            }

            $postId = $fresh->id;
            $workspaceId = $fresh->workspace_id;

            $fresh->delete();

            return [$postId, $workspaceId];
        });

        PostDeleted::dispatch($postId, $workspaceId);
    }
}
