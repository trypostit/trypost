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
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostApproval;
use App\Support\PostCompositionValidator;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use App\Support\Social\AbandonGoogleBusinessReview;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
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

        if ($post->postPlatforms()->enabled()->count() === 1) {
            $selectedTarget = $post->postPlatforms()->enabled()->sole();
            if (array_key_exists('platforms', $data)) {
                if (count($data['platforms']) !== 1 || data_get($data, 'platforms.0.id') !== $selectedTarget->id
                    || array_key_exists('content_type', $data) || array_key_exists('meta', $data)) {
                    throw ValidationException::withMessages(['platforms' => __('validation.in', ['attribute' => 'platforms'])]);
                }

                $data = [
                    ...Arr::except($data, ['platforms']),
                    ...Arr::only($data['platforms'][0], ['content_type', 'meta']),
                ];
            }

            return self::updateChannelPost($workspace, $post, $data, $actor);
        }

        if (filled(data_get($data, 'queue'))) {
            throw ValidationException::withMessages(['queue' => __('posts.errors.queue_legacy_post')]);
        }

        if (array_key_exists('content_type', $data) || array_key_exists('meta', $data)) {
            return self::updateChannelPost($workspace, $post, $data, $actor);
        }

        return MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($workspace, $post, $data, $actor): array {
            $occurrence = $post->currentOccurrence();
            $previousStatus = $post->status;
            $scheduledAt = $post->scheduled_at;
            if (data_get($data, 'scheduled_at')) {
                $scheduledAt = Carbon::parse(data_get($data, 'scheduled_at'))->utc();
            }

            $status = (string) data_get($data, 'status', $post->status->value);
            $storedStatus = PostApproval::isRequired($workspace, $actor, $status)
                ? PostStatus::PendingApproval
                : PostStatus::from($status);
            $publishRequest = ($storedStatus === PostStatus::PendingApproval || $previousStatus === PostStatus::PendingApproval)
                && $status === PostStatus::Publishing->value;

            $post->update([
                'content' => data_get($data, 'content', $post->content),
                'status' => $storedStatus,
                'scheduled_at' => $publishRequest ? null : $scheduledAt,
                'schedule_mode' => match (true) {
                    $storedStatus === PostStatus::Scheduled => ScheduleMode::Custom,
                    $storedStatus === PostStatus::PendingApproval && ! $publishRequest && $scheduledAt !== null => ScheduleMode::Custom,
                    default => null,
                },
                ...PostApproval::transition($previousStatus, $storedStatus, $actor),
            ]);

            if (Arr::has($data, 'media')) {
                SyncOwnedMedia::execute($post, data_get($data, 'media') ?? [], $batch);
            }

            if (Arr::has($data, 'label_ids')) {
                $post->labels()->sync(data_get($data, 'label_ids', []));
            }

            if (Arr::has($data, 'platforms')) {
                $post->postPlatforms()->update(['enabled' => false]);

                foreach (data_get($data, 'platforms', []) as $platformData) {
                    $updateData = ['enabled' => true];

                    if (data_get($platformData, 'content_type') !== null) {
                        $updateData['content_type'] = data_get($platformData, 'content_type');
                    }

                    if (data_get($platformData, 'meta') !== null) {
                        $postPlatform = $post->postPlatforms()->where('id', data_get($platformData, 'id'))->first();

                        if ($postPlatform) {
                            $updateData['meta'] = PostPlatformMetaRules::normalize(array_filter(
                                array_merge($postPlatform->meta ?? [], data_get($platformData, 'meta') ?? []),
                                fn (mixed $value): bool => $value !== null,
                            ));
                        }
                    }

                    $post->postPlatforms()
                        ->where('id', data_get($platformData, 'id'))
                        ->update($updateData);
                }
            }

            if (in_array($post->status, [PostStatus::Scheduled, PostStatus::Publishing], true)) {
                PostPlatformMetaRules::assertStoredPostPublishable(
                    $post,
                    collect(data_get($data, 'platforms', []))->pluck('id')->all(),
                );
            }

            if (Arr::has($data, 'platforms')) {
                $post->postPlatforms()
                    ->disabled()
                    ->where('platform', Platform::GoogleBusiness)
                    ->where('status', PlatformStatus::PendingReview)
                    ->get()
                    ->each(fn (PostPlatform $platform) => AbandonGoogleBusinessReview::execute(
                        $platform,
                        __('posts.errors.target_disabled'),
                        ['category' => 'target_disabled'],
                    ));

                $disabledGoogleBusinessIds = $post->postPlatforms()
                    ->disabled()
                    ->where('platform', Platform::GoogleBusiness)
                    ->pluck('id');

                DB::afterCommit(function () use ($disabledGoogleBusinessIds): void {
                    $disabledGoogleBusinessIds->each(
                        fn (string $id) => app(GoogleBusinessDerivativeCleaner::class)->cleanup($id),
                    );
                });
            }

            return self::finish($post, $previousStatus, $storedStatus, $occurrence, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{post: Post, action: PostAction|null}
     */
    private static function updateChannelPost(Workspace $workspace, Post $post, array $data, ?User $actor): array
    {
        if (array_key_exists('platforms', $data)) {
            throw ValidationException::withMessages(['platforms' => __('validation.in', ['attribute' => 'platforms'])]);
        }

        if ($post->postPlatforms()->enabled()->count() !== 1) {
            throw ValidationException::withMessages(['post' => PostStatusRules::editBlockedMessage()]);
        }

        $target = $post->postPlatforms()->enabled()->sole();
        $position = filled(data_get($data, 'queue')) ? QueuePosition::from(data_get($data, 'queue')) : null;
        $channel = $target->socialAccount;

        if ($position !== null && ! $channel?->hasPostingSchedule()) {
            throw ValidationException::withMessages(['queue' => __('posts.errors.queue_requires_schedule')]);
        }

        $previousStatus = $post->status;
        $keepsPending = $previousStatus === PostStatus::PendingApproval && ! array_key_exists('status', $data);
        $status = $keepsPending ? PostStatus::Draft->value : (string) data_get($data, 'status', $previousStatus->value);
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
            array_merge($target->meta ?? [], data_get($data, 'meta') ?? []),
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
                'social_account_id' => $target->social_account_id,
                'content_type' => data_get($data, 'content_type') ?? $target->content_type->value,
                'meta' => $meta,
            ]],
        ], $post->media ?? []);

        $write = fn (): array => MediaCopyBatch::run(function (MediaCopyBatch $batch) use ($post, $target, $channel, $data, $resolved, $meta, $scheduledAt, $mode, $position, $pending, $approvesHolder, $previousStatus, $storedStatus, $actor): array {
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
            ]);
            SyncOwnedMedia::execute($post, $destination['media'], $batch);
            $target->update([
                'content_type' => $destination['content_type'],
                'meta' => $meta,
            ]);

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
            : ReflowChannelQueue::withLock([$target->social_account_id], $write);
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
