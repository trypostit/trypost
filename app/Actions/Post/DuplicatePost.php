<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Clones a Post (and its still-connected platform rows + label associations)
 * into a fresh Draft. The new post is owned by the actor and unscheduled —
 * the user picks a new date in the editor.
 *
 * Only platform rows with a still-existing social account are copied
 * (`whereHas('socialAccount')`), so orphan history rows left after disconnect
 * never land on the new draft.
 */
class DuplicatePost
{
    public static function execute(Post $original, User $user): Post
    {
        return DB::transaction(function () use ($original, $user): Post {
            $copy = $original->workspace->posts()->create([
                'user_id' => $user->id,
                'content' => $original->content,
                'media' => $original->media,
                'status' => PostStatus::Draft,
                'created_via' => CreatedVia::Web,
                'scheduled_at' => null,
                'published_at' => null,
            ]);

            $platforms = $original->postPlatforms()
                ->whereHas('socialAccount')
                ->get();

            foreach ($platforms as $platform) {
                $copy->postPlatforms()->create([
                    'social_account_id' => $platform->social_account_id,
                    'platform' => $platform->platform,
                    'platform_name' => $platform->platform_name,
                    'platform_username' => $platform->platform_username,
                    'platform_avatar' => $platform->getRawOriginal('platform_avatar'),
                    'content_type' => $platform->content_type,
                    'enabled' => $platform->enabled,
                    'meta' => $platform->meta,
                    // Always reset platform-level status — never carry
                    // published/failed/publishing into the new draft.
                    'status' => PostPlatformStatus::Pending,
                    'platform_post_id' => null,
                    'platform_url' => null,
                    'error_message' => null,
                    'error_context' => null,
                    'published_at' => null,
                ]);
            }

            $copy->labels()->attach($original->labels->pluck('id'));

            return $copy;
        });
    }
}
