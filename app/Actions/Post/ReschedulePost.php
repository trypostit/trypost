<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Actions\Post\Queue\MoveChannelPostToQueueSlot;
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
     * Moves a post to the instant it receives. A draft stays a draft and only changes its date.
     * A queued post stays in the queue only when the client asks for it ($keepInQueue, a drop on
     * a slot of its own channel); the slot is checked under the channel lock and a taken one fails
     * with queue_order_stale. Without it a queued post becomes custom, as the user confirmed.
     * The write goes through UpdatePost with the acting user, so PostApproval still decides.
     *
     * @return array{post: Post, action: PostAction|null}
     */
    public static function execute(Workspace $workspace, Post $post, ?CarbonImmutable $at, User $actor, bool $keepInQueue = false): array
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

        if ($keepInQueue && $post->status === PostStatus::Scheduled && $post->schedule_mode === ScheduleMode::Queue) {
            MoveChannelPostToQueueSlot::handle($post->socialAccount, $post->id, $at, $actor);

            return ['post' => $post->refresh(), 'action' => null];
        }

        return UpdatePost::execute($workspace, $post, [
            'status' => PostStatus::Scheduled->value,
            'scheduled_at' => $at->toIso8601String(),
        ], $actor);
    }
}
