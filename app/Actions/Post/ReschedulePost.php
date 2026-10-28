<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostApproval;
use Illuminate\Validation\ValidationException;

class ReschedulePost
{
    /**
     * Moves a post to the instant it receives. A draft stays a draft and only changes its date.
     * A queued post becomes custom at the instant it receives, wherever it lands.
     * The write goes through UpdatePost with the acting user, so PostApproval still decides.
     * The post is re-read under its approval lock: a post waiting for approval, or a scheduled
     * one due within a minute, keeps its time.
     *
     * @return array{post: Post, action: PostAction|null}
     */
    public static function execute(Workspace $workspace, Post $post, ?string $scheduledAt, User $actor): array
    {
        if (! $post->hasChannel()) {
            throw ValidationException::withMessages(['scheduled_at' => __('posts.errors.choose_channel')]);
        }

        return PostApproval::locked($post, function () use ($workspace, $post, $scheduledAt, $actor): array {
            if ($post->status === PostStatus::PendingApproval) {
                throw ValidationException::withMessages(['scheduled_at' => __('calendar.reschedule.errors.pending')]);
            }

            if ($post->status === PostStatus::Scheduled && filled($post->scheduled_at) && ! $post->scheduled_at->isAfter(now()->addMinute())) {
                throw ValidationException::withMessages(['scheduled_at' => __('calendar.reschedule.errors.publishing_soon')]);
            }

            return UpdatePost::execute($workspace, $post, [
                'status' => $post->status === PostStatus::Draft ? PostStatus::Draft->value : PostStatus::Scheduled->value,
                'scheduled_at' => $scheduledAt,
            ], $actor);
        });
    }
}
