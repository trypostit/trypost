<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Post\Queue\MoveChannelPostToQueueSlot;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostApproval;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ReschedulePost
{
    /**
     * Moves a post to the instant it receives. A draft stays a draft and only changes its date.
     * A null instant (a drop on No Date) takes the post off the schedule: it becomes a draft
     * without a date, which frees a queued post's slot and never needs approval.
     * A scheduled post sent with `$queueSlot` goes into its channel's queue at that free slot
     * (MoveChannelPostToQueueSlot, which refuses a taken slot or an instant that is not one).
     * A slot past the queue window (MoveChannelPostToQueueSlot::withinQueueWindow) counts as a
     * custom time, like any other drop: a queued post becomes custom at the instant it receives.
     * The write goes through UpdatePost with the acting user, so PostApproval still decides.
     * The post is re-read under its approval lock: a post waiting for approval, or a scheduled
     * one due within a minute, keeps its time. The channel queue lock is taken inside the
     * approval lock, the same order UpdatePost, ApprovePost and AppendPostMedia use.
     *
     * @return array{post: Post, action: PostAction|null}
     */
    public static function execute(Workspace $workspace, Post $post, ?string $scheduledAt, User $actor, bool $queueSlot = false): array
    {
        if (! $post->hasChannel()) {
            throw ValidationException::withMessages(['scheduled_at' => __('posts.errors.choose_channel')]);
        }

        return PostApproval::locked($post, function () use ($workspace, $post, $scheduledAt, $actor, $queueSlot): array {
            if ($post->status === PostStatus::PendingApproval) {
                throw ValidationException::withMessages(['scheduled_at' => __('calendar.reschedule.errors.pending')]);
            }

            if ($post->status === PostStatus::Scheduled && filled($post->scheduled_at) && ! $post->scheduled_at->isAfter(now()->addMinute())) {
                throw ValidationException::withMessages(['scheduled_at' => __('calendar.reschedule.errors.publishing_soon')]);
            }

            $slotAt = filled($scheduledAt) ? CarbonImmutable::parse($scheduledAt)->utc() : null;

            if ($queueSlot && filled($slotAt) && $post->status === PostStatus::Scheduled && MoveChannelPostToQueueSlot::withinQueueWindow($slotAt)) {
                return MoveChannelPostToQueueSlot::handle($post->socialAccount, $post->id, $slotAt, $actor);
            }

            return UpdatePost::execute($workspace, $post, [
                'status' => blank($scheduledAt) || $post->status === PostStatus::Draft ? PostStatus::Draft->value : PostStatus::Scheduled->value,
                'scheduled_at' => $scheduledAt,
            ], $actor);
        });
    }
}
