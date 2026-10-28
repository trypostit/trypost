<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Post\Queue\MoveChannelPostToQueueSlot;
use App\Actions\Post\Queue\ReflowChannelQueue;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class ReschedulePost
{
    /**
     * Moves a post to the instant it receives. A draft stays a draft and only changes its date;
     * a queued post stays in the queue only when the target is a free slot of its own channel.
     * The write goes through UpdatePost with the acting user, so PostApproval still decides.
     *
     * @return array{post: Post, action: PostAction|null}
     */
    public static function execute(Workspace $workspace, Post $post, ?CarbonImmutable $at, User $actor): array
    {
        if (! $post->hasChannel()) {
            throw ValidationException::withMessages(['scheduled_at' => __('posts.errors.choose_channel')]);
        }

        if ($post->status === PostStatus::Draft) {
            return UpdatePost::execute($workspace, $post, [
                'status' => PostStatus::Draft->value,
                'scheduled_at' => $at?->toIso8601String(),
            ], $actor);
        }

        if ($at === null) {
            throw ValidationException::withMessages(['scheduled_at' => __('validation.required', ['attribute' => 'scheduled at'])]);
        }

        if ($post->schedule_mode === ScheduleMode::Queue && ReflowChannelQueue::isFreeSlot($post->socialAccount, $at, $post->id)) {
            MoveChannelPostToQueueSlot::handle($post->socialAccount, $post->id, $at, $actor);

            return ['post' => $post->refresh(), 'action' => null];
        }

        return UpdatePost::execute($workspace, $post, [
            'status' => PostStatus::Scheduled->value,
            'scheduled_at' => $at->toIso8601String(),
        ], $actor);
    }
}
