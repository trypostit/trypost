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
     * A scheduled post sent with `$queueSlot` goes into its channel's queue at that free slot
     * (MoveChannelPostToQueueSlot, which refuses a taken slot or an instant that is not one);
     * otherwise a queued post becomes custom at the instant it receives.
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

            if ($queueSlot && $post->status === PostStatus::Scheduled) {
                MoveChannelPostToQueueSlot::handle($post->socialAccount, $post->id, CarbonImmutable::parse($scheduledAt)->utc(), $actor);

                $post->refresh();

                return ['post' => $post, 'action' => $post->status === PostStatus::PendingApproval ? PostAction::PendingApproval : PostAction::Scheduled];
            }

            return UpdatePost::execute($workspace, $post, [
                'status' => $post->status === PostStatus::Draft ? PostStatus::Draft->value : PostStatus::Scheduled->value,
                'scheduled_at' => $scheduledAt,
            ], $actor);
        });
    }
}
