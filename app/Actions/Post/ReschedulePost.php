<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Action as PostAction;
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
     * A queued post becomes custom at the instant it receives, wherever it lands.
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

        if (blank($at)) {
            throw ValidationException::withMessages(['scheduled_at' => __('validation.required', ['attribute' => 'scheduled at'])]);
        }

        return UpdatePost::execute($workspace, $post, [
            'status' => PostStatus::Scheduled->value,
            'scheduled_at' => $at->toIso8601String(),
        ], $actor);
    }
}
