<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Media\SyncOwnedMedia;
use App\Actions\Post\Approval\NotifyApprovalDecision;
use App\Actions\Post\Approval\NotifyApprovalRequested;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\ApprovalDecision;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostApproval;
use App\Support\PostCompositionValidator;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class UpdatePost
{
    /**
     * `$actor` is the user making the change; system callers pass none and are never gated.
     * A pending post is changed under its approval lock (see PostApproval::whilePending()).
     * `queue_slot` (an instant, set only by MoveChannelPostToQueueSlot under the channel lock)
     * stores a single-channel post in queue mode at that slot without reflowing the queue.
     *
     * @return array{post: Post, action: PostAction|null}
     */
    public static function execute(Workspace $workspace, Post $post, array $data, ?User $actor = null): array
    {
        return $post->status === PostStatus::PendingApproval
            ? PostApproval::whilePending($post, fn (): array => self::apply($workspace, $post, $data, $actor))
            : self::apply($workspace, $post, $data, $actor);
    }

    /**
     * @return array{post: Post, action: PostAction|null}
     */
    private static function apply(Workspace $workspace, Post $post, array $data, ?User $actor): array
    {
        if (PostStatusRules::blocksEditing($post)) {
            return ['post' => $post, 'action' => PostAction::Finalized];
        }

        if (array_key_exists('social_account_id', $data)) {
            throw ValidationException::withMessages(['social_account_id' => __('validation.in', ['attribute' => 'social account'])]);
        }

        if (array_key_exists('platforms', $data)) {
            throw ValidationException::withMessages(['platforms' => __('validation.prohibited', ['attribute' => 'platforms'])]);
        }

        return $post->hasChannel()
            ? self::updateChannelPost($workspace, $post, $data, $actor)
            : self::updateDraftWithoutChannel($post, $data);
    }

    /**
     * A draft written before posts had one channel each can still be edited,
     * but it stays a draft: it goes out only after the composer turns it into
     * a post for a channel (RecoverEmptyDraft).
     *
     * @param  array<string, mixed>  $data
     * @return array{post: Post, action: PostAction|null}
     */
    private static function updateDraftWithoutChannel(Post $post, array $data): array
    {
        $status = (string) data_get($data, 'status', $post->status->value);

        if ($status !== PostStatus::Draft->value || filled(data_get($data, 'queue')) || filled(data_get($data, 'queue_slot'))) {
            throw ValidationException::withMessages(['status' => __('posts.errors.choose_channel')]);
        }

        if (array_key_exists('content_type', $data) || array_key_exists('meta', $data)) {
            throw ValidationException::withMessages(['content_type' => __('posts.errors.choose_channel')]);
        }

        return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($post, $data): array {
            if (self::finalizedMeanwhile($post)) {
                return ['post' => $post, 'action' => PostAction::Finalized];
            }

            $post->update([
                'content' => data_get($data, 'content', $post->content),
                'scheduled_at' => array_key_exists('scheduled_at', $data) && filled(data_get($data, 'scheduled_at'))
                    ? Carbon::parse(data_get($data, 'scheduled_at'))->utc()
                    : $post->scheduled_at,
            ]);

            if (Arr::has($data, 'media')) {
                SyncOwnedMedia::execute($post, data_get($data, 'media') ?? [], $batch);
            }

            if (Arr::has($data, 'label_ids')) {
                $post->labels()->sync(data_get($data, 'label_ids', []));
            }

            return ['post' => $post, 'action' => null];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{post: Post, action: PostAction|null}
     */
    private static function updateChannelPost(Workspace $workspace, Post $post, array $data, ?User $actor): array
    {
        $position = filled(data_get($data, 'queue')) ? QueuePosition::from(data_get($data, 'queue')) : null;
        $channel = $post->socialAccount;

        if ($position !== null && ! $channel?->hasPostingSchedule()) {
            throw ValidationException::withMessages(['queue' => __('posts.errors.queue_requires_schedule')]);
        }

        $previousStatus = $post->status;
        $keepsPending = $previousStatus === PostStatus::PendingApproval && ! array_key_exists('status', $data);
        $status = $keepsPending ? PostStatus::Draft->value : (string) data_get($data, 'status', $previousStatus->value);

        if ($position === null && $channel?->hasPostingSchedule() && self::keepsQueuedTime($post, $data, $status)) {
            $position = QueuePosition::Next;
        }
        $pending = $keepsPending || PostApproval::isRequired($workspace, $actor, $status);
        $keepsQueueSlot = $position === QueuePosition::Next
            && $post->schedule_mode === ScheduleMode::Queue
            && $post->scheduled_at?->isFuture()
            && ($previousStatus === PostStatus::Scheduled
                || ($previousStatus === PostStatus::PendingApproval && ReflowChannelQueue::isFreeSlot($channel, $post->scheduled_at, $post->id)));

        $approvesHolder = $keepsQueueSlot && $previousStatus === PostStatus::PendingApproval && ! $pending;

        if ($keepsQueueSlot) {
            $position = null;
        }

        if ($keepsPending) {
            $position = $post->approval_queue_position;
        }

        $queueSlot = $position === null && ! $keepsPending ? data_get($data, 'queue_slot') : null;

        $meta = PostPlatformMetaRules::normalize(array_filter(
            array_merge($post->meta ?? [], data_get($data, 'meta') ?? []),
            fn (mixed $value): bool => $value !== null,
        ));
        $scheduledAt = match (true) {
            $keepsPending => $post->scheduled_at?->toIso8601String(),
            $position !== null => null,
            $keepsQueueSlot => $post->scheduled_at->toIso8601String(),
            filled($queueSlot) => $queueSlot,
            ($pending || $previousStatus === PostStatus::PendingApproval) && $status === PostStatus::Publishing->value => null,
            array_key_exists('scheduled_at', $data) => data_get($data, 'scheduled_at'),
            default => $post->scheduled_at?->toIso8601String(),
        };
        $mode = match (true) {
            $keepsPending => $post->schedule_mode,
            $position !== null => ScheduleMode::Queue,
            $status !== PostStatus::Scheduled->value => null,
            $keepsQueueSlot => ScheduleMode::Queue,
            filled($queueSlot) => ScheduleMode::Queue,
            filled(data_get($data, 'scheduled_at')) => ScheduleMode::Custom,
            $pending => ScheduleMode::Custom,
            default => $post->schedule_mode ?? ScheduleMode::Custom,
        };
        $storedStatus = $pending ? PostStatus::PendingApproval : PostStatus::from($status);
        $resolved = PostCompositionValidator::validate($workspace, [
            'status' => $status,
            'queue' => $keepsPending ? null : $position?->value,
            'content' => array_key_exists('content', $data) ? $data['content'] : $post->content,
            'media' => data_get($data, 'media') ?? $post->media ?? [],
            'scheduled_at' => $keepsPending ? null : $scheduledAt,
            'label_ids' => data_get($data, 'label_ids') ?? $post->labels()->pluck('workspace_labels.id')->all(),
            'destinations' => [[
                'social_account_id' => $post->social_account_id,
                'content_type' => data_get($data, 'content_type')
                    ?? (array_key_exists('media', $data) && ContentType::derivesFromMedia($post->platform) ? null : $post->content_type->value),
                'meta' => $meta,
            ]],
        ], $post->media ?? []);

        $write = fn (): array => MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($post, $channel, $data, $resolved, $scheduledAt, $mode, $position, $pending, $approvesHolder, $previousStatus, $storedStatus, $actor): array {
            if (self::finalizedMeanwhile($post)) {
                return ['post' => $post, 'action' => PostAction::Finalized];
            }

            $slotLost = $approvesHolder && ! ReflowChannelQueue::isFreeSlot($channel->refresh(), $post->scheduled_at, $post->id);
            $position = $slotLost ? QueuePosition::Next : $position;
            $destination = $resolved['destinations'][0];
            $occurrence = $post->currentOccurrence();
            $post->update([
                'content' => $destination['content'],
                'status' => $storedStatus,
                'scheduled_at' => $scheduledAt && ! $slotLost ? Carbon::parse($scheduledAt)->utc() : null,
                'schedule_mode' => $mode,
                ...PostApproval::transition($previousStatus, $storedStatus, $actor, $pending ? $position : null),
                'content_type' => $destination['content_type'],
                'meta' => PostPlatformMetaRules::forStorage($post->meta ?? [], $destination['meta']),
            ]);
            SyncOwnedMedia::execute($post, $destination['media'], $batch);

            if (array_key_exists('label_ids', $data)) {
                $post->labels()->sync(data_get($data, 'label_ids'));
            }

            if ($position !== null && ! $pending) {
                CreateChannelPost::enqueue($channel, $post, $position);
            }

            return self::finish($post, $previousStatus, $storedStatus, $occurrence, $actor);
        });

        return ($position === null && ! $approvesHolder) || $pending
            ? $write()
            : ReflowChannelQueue::withLock([$post->social_account_id], $write);
    }

    /**
     * A partial edit (API, MCP, media attach) of a queued post that names neither a
     * queue position nor a time keeps it in the queue, as the composer does by
     * re-sending `next`, so a request to approve it still holds its slot.
     *
     * @param  array<string, mixed>  $data
     */
    private static function keepsQueuedTime(Post $post, array $data, string $status): bool
    {
        return $status === PostStatus::Scheduled->value
            && $post->schedule_mode === ScheduleMode::Queue
            && in_array($post->status, [PostStatus::Scheduled, PostStatus::PendingApproval], true)
            && $post->scheduled_at?->isFuture() === true
            && ! array_key_exists('queue', $data)
            && blank(data_get($data, 'scheduled_at'))
            && blank(data_get($data, 'queue_slot'));
    }

    /**
     * Re-reads the post under a row lock inside the write transaction, so a post the
     * scheduler claimed or another request settled after it was loaded is left alone.
     */
    private static function finalizedMeanwhile(Post $post): bool
    {
        $locked = Post::query()->whereKey($post->id)->lockForUpdate()->firstOrFail();

        if (! PostStatusRules::blocksEditing($locked)) {
            return false;
        }

        $post->setRawAttributes($locked->getAttributes(), true);

        return true;
    }

    /**
     * @return array{post: Post, action: PostAction|null}
     */
    private static function finish(Post $post, PostStatus $previousStatus, PostStatus $storedStatus, ?CarbonInterface $occurrence, ?User $actor): array
    {
        if ($actor !== null && PostApproval::isRequest($previousStatus, $storedStatus)) {
            NotifyApprovalRequested::execute(collect([$post]), $actor);
        }

        if ($actor !== null && PostApproval::isApproval($previousStatus, $storedStatus)) {
            NotifyApprovalDecision::execute($post, ApprovalDecision::Approved, $actor, $post->approvalRequester());
        }

        if ($storedStatus === PostStatus::Publishing) {
            $post->moveScheduleToNow($occurrence);
            PublishPost::dispatch($post)->afterCommit();

            return ['post' => $post, 'action' => PostAction::Publishing];
        }

        return match ($storedStatus) {
            PostStatus::PendingApproval => ['post' => $post, 'action' => PostAction::PendingApproval],
            PostStatus::Scheduled => ['post' => $post, 'action' => PostAction::Scheduled],
            default => ['post' => $post, 'action' => null],
        };
    }
}
