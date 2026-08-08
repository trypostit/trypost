<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Models\Post;

class SyncPostPlatforms
{
    /**
     * Ensure the post has a post_platform row for every currently-active social
     * account in its workspace. New rows are created with enabled=false so the
     * user can opt into the additional accounts via the Schedule tab without
     * losing existing toggle state.
     */
    public static function execute(Post $post): void
    {
        $workspace = $post->workspace;

        $existingAccountIds = $post->postPlatforms()->pluck('social_account_id')->filter();

        $missingAccounts = $workspace->socialAccounts()
            ->active()
            ->whereNotIn('id', $existingAccountIds)
            ->get();

        foreach ($missingAccounts as $account) {
            $post->postPlatforms()->create([
                'social_account_id' => $account->id,
                'platform' => $account->platform->value,
                'platform_name' => $account->accountDisplayName(),
                'platform_username' => $account->username,
                'platform_avatar' => $account->getRawOriginal('avatar_url'),
                'content_type' => ContentType::defaultFor($account->platform),
                'status' => PostPlatformStatus::Pending,
                'enabled' => false,
            ]);
        }
    }
}
