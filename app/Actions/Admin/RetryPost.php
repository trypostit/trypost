<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Post\RetryFailedPost;
use App\Enums\Admin\AdminActionType;
use App\Enums\Post\RetryOutcome;
use App\Models\Post;
use App\Models\User;

class RetryPost
{
    public function __construct(
        private readonly RetryFailedPost $retryFailedPost,
        private readonly RecordAdminAction $recordAdminAction,
    ) {}

    /**
     * An admin retries a failed post; a queued attempt goes in the
     * activity log.
     */
    public function execute(User $admin, Post $post, bool $instagramCommentChecked): RetryOutcome
    {
        $outcome = $this->retryFailedPost->execute($post, $instagramCommentChecked);

        if ($outcome === RetryOutcome::Queued) {
            $this->recordAdminAction->execute($admin, AdminActionType::RetryPost, $post, ['platform' => $post->platform?->value]);
        }

        return $outcome;
    }
}
