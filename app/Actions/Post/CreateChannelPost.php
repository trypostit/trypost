<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Carbon\Carbon;

class CreateChannelPost
{
    /**
     * Create one post with exactly one publishing target from a resolved destination.
     *
     * @param  array<string, mixed>  $destination
     */
    public static function execute(Workspace $workspace, User $user, array $destination): Post
    {
        $account = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($destination['social_account_id']);

        $post = $workspace->posts()->create([
            'user_id' => $user->id,
            'content' => $destination['content'],
            'media' => $destination['media'],
            'status' => PostStatus::from($destination['status']),
            'created_via' => $destination['created_via'] ?? null,
            'scheduled_at' => isset($destination['scheduled_at'])
                ? Carbon::parse($destination['scheduled_at'])->utc()
                : null,
        ]);

        $post->postPlatforms()->create([
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'platform_name' => $account->accountDisplayName(),
            'platform_username' => $account->username,
            'platform_avatar' => $account->getRawOriginal('avatar_url'),
            'content_type' => $destination['content_type'],
            'status' => PostPlatformStatus::Pending,
            'enabled' => true,
            'meta' => $destination['meta'] ?? [],
        ]);

        if ($destination['label_ids'] !== []) {
            $post->labels()->sync($destination['label_ids']);
        }

        return $post;
    }
}
