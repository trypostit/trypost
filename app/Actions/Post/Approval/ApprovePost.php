<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\PostApproval;
use App\Support\PostStatusRules;
use Illuminate\Validation\ValidationException;

class ApprovePost
{
    /**
     * Schedules a pending post exactly as its author asked (queue position or
     * time), or at the approver's `scheduled_at` / `publish_now` choice.
     *
     * @param  array{publish_now?: bool, scheduled_at?: string|null}  $choice
     * @return array{post: Post, action: PostAction|null}
     */
    public static function execute(Post $post, User $approver, array $choice = []): array
    {
        return PostApproval::whilePending($post, function () use ($post, $approver, $choice): array {
            $data = match (true) {
                (bool) data_get($choice, 'publish_now') => ['status' => PostStatus::Publishing->value],
                filled(data_get($choice, 'scheduled_at')) => [
                    'status' => PostStatus::Scheduled->value,
                    'scheduled_at' => data_get($choice, 'scheduled_at'),
                ],
                $post->schedule_mode === ScheduleMode::Queue => [
                    'status' => PostStatus::Scheduled->value,
                    'queue' => ($post->approval_queue_position ?? QueuePosition::Next)->value,
                ],
                $post->scheduled_at?->isFuture() === true => [
                    'status' => PostStatus::Scheduled->value,
                    'scheduled_at' => $post->scheduled_at->toIso8601String(),
                ],
                default => throw ValidationException::withMessages(['scheduled_at' => __('posts.approvals.errors.time_passed')]),
            };

            PostStatusRules::assertStoredPostPublishable($post);

            return UpdatePost::execute($post->workspace, $post, $data, $approver);
        });
    }
}
