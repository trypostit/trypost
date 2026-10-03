<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\SyncOwnedMedia;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostApproval;
use App\Support\PostPlatformMetaRules;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateChannelPost
{
    /**
     * Create one post with exactly one publishing target from a resolved destination.
     * A destination with a `queue` position is enqueued on its channel, so the caller
     * must hold that channel's queue lock and run this inside a DB transaction.
     * The destination's media ends owned by the new post (see SyncOwnedMedia).
     *
     * @param  array<string, mixed>  $destination
     */
    public static function execute(Workspace $workspace, User $user, array $destination, MediaCopyBatch $batch): Post
    {
        $account = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($destination['social_account_id']);

        $status = PostStatus::from($destination['status']);
        $position = data_get($destination, 'queue');
        $pending = $status === PostStatus::PendingApproval;

        $post = $workspace->posts()->create([
            'post_group_id' => data_get($destination, 'post_group_id') ?? (string) Str::uuid7(),
            'user_id' => $user->id,
            'content' => $destination['content'],
            'media' => [],
            'status' => $status,
            'schedule_mode' => match (true) {
                $position instanceof QueuePosition, (bool) data_get($destination, 'queue_slot') => ScheduleMode::Queue,
                $status === PostStatus::Scheduled => ScheduleMode::Custom,
                $pending && filled(data_get($destination, 'scheduled_at')) => ScheduleMode::Custom,
                default => null,
            },
            'created_via' => $destination['created_via'] ?? null,
            'scheduled_at' => ! $position instanceof QueuePosition && isset($destination['scheduled_at'])
                ? Carbon::parse($destination['scheduled_at'])->utc()
                : null,
            ...($pending ? PostApproval::transition(PostStatus::Draft, PostStatus::PendingApproval, $user, $position instanceof QueuePosition ? $position : null) : []),
        ]);

        $post->postPlatforms()->create([
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            ...$account->channelSnapshot(),
            'content_type' => $destination['content_type'],
            'status' => PostPlatformStatus::Pending,
            'enabled' => true,
            'meta' => PostPlatformMetaRules::normalize($destination['meta'] ?? []),
        ]);

        SyncOwnedMedia::execute(
            $post,
            data_get($destination, 'media', []),
            $batch,
            data_get($destination, 'media_error_key', 'media'),
            data_get($destination, 'legacy_media', []),
        );

        if ($destination['label_ids'] !== []) {
            $post->labels()->sync($destination['label_ids']);
        }

        if ($position instanceof QueuePosition && ! $pending) {
            self::enqueue($account, $post, $position);
        }

        return $post;
    }

    /**
     * Places a scheduled post in its channel queue; a queued post never commits without a time.
     */
    public static function enqueue(SocialAccount $account, Post $post, QueuePosition $position): void
    {
        ReflowChannelQueue::handleLocked($account, $post, $position);

        if ($post->scheduled_at === null) {
            throw ValidationException::withMessages(['queue' => __('posts.errors.queue_requires_schedule')]);
        }
    }
}
