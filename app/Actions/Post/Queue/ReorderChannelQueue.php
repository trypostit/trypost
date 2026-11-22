<?php

declare(strict_types=1);

namespace App\Actions\Post\Queue;

use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\ChannelPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReorderChannelQueue
{
    /**
     * Reassigns the slots the given posts already occupy to the given order.
     * The ids must be exactly the first count($postIds) reorderable queued posts of the channel;
     * the posts after that prefix are left untouched.
     *
     * @param  list<string>  $postIds
     */
    public static function handle(SocialAccount $channel, array $postIds, User $actor): void
    {
        ReflowChannelQueue::withLock([$channel->id], function () use ($channel, $postIds, $actor): void {
            $queued = Post::query()
                ->queuedOn($channel->id, now()->addMinute())
                ->orderBy('scheduled_at')
                ->orderBy('id')
                ->limit(count($postIds))
                ->get()
                ->keyBy('id');

            if (count($postIds) !== $queued->count() || count(array_unique($postIds)) !== count($postIds) || array_diff($postIds, $queued->keys()->all()) !== []) {
                throw ValidationException::withMessages(['post_ids' => __('posts.errors.queue_order_stale')]);
            }

            $slots = $queued->pluck('scheduled_at')->sort()->values();

            $slotsById = collect(array_values($postIds))->mapWithKeys(fn (string $postId, int $index): array => [$postId => $slots[$index]]);

            DB::transaction(function () use ($queued, $slotsById, $channel, $actor): void {
                foreach ($slotsById->sortKeys(SORT_STRING) as $postId => $slot) {
                    $post = $queued->get($postId);

                    if ($post->scheduled_at->equalTo($slot)) {
                        continue;
                    }

                    ChannelPermissions::ensureScheduledPostCanChange($channel, $post->meta, $actor, 'post_ids');
                    ReflowChannelQueue::updateIfScheduled($post, ['scheduled_at' => $slot]);
                }
            });
        });
    }
}
