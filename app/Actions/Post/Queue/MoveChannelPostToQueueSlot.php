<?php

declare(strict_types=1);

namespace App\Actions\Post\Queue;

use App\Actions\Post\BuildPublishPageProps;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class MoveChannelPostToQueueSlot
{
    /**
     * Moves a scheduled post of the channel onto one of its free posting slots, as a queue post.
     * The write goes through UpdatePost with the acting user, so PostApproval still decides.
     */
    public static function handle(SocialAccount $channel, string $postId, CarbonImmutable $slotAt, User $actor): void
    {
        ReflowChannelQueue::withLock([$channel->id], function () use ($channel, $postId, $slotAt, $actor): void {
            $channel->refresh();

            $post = Post::query()
                ->where('workspace_id', $channel->workspace_id)
                ->scheduledOn($channel->id, now()->addMinute())
                ->find($postId);

            if ($post === null || ! self::isFreeSlot($channel, $post, $slotAt)) {
                throw ValidationException::withMessages(['slot_at' => __('posts.errors.queue_order_stale')]);
            }

            if ($post->postPlatforms()->enabled()->count() !== 1) {
                throw ValidationException::withMessages(['queue' => __('posts.errors.queue_legacy_post')]);
            }

            UpdatePost::execute($channel->workspace, $post, [
                'status' => PostStatus::Scheduled->value,
                'queue_slot' => $slotAt->utc()->toIso8601String(),
            ], $actor);
        });
    }

    private static function isFreeSlot(SocialAccount $channel, Post $post, CarbonImmutable $slotAt): bool
    {
        return $slotAt->lessThanOrEqualTo(now()->addDays(BuildPublishPageProps::MAX_QUEUE_DAYS))
            && ReflowChannelQueue::isFreeSlot($channel, $slotAt, $post->id);
    }
}
