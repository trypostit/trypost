<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RecordAdminAction;
use App\Actions\Post\RetryFailedPost;
use App\Enums\Admin\AdminActionType;
use App\Enums\Post\RetryOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\RetryPostRequest;
use App\Models\Post;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

class PostRetryController extends Controller
{
    public function store(
        RetryPostRequest $request,
        Post $post,
        RetryFailedPost $retryFailedPost,
        RecordAdminAction $recordAdminAction,
    ): RedirectResponse {
        $outcome = $retryFailedPost->execute($post, $request->boolean('instagram_comment_checked'));

        return match ($outcome) {
            RetryOutcome::Queued => $this->queued($request->user(), $post, $recordAdminAction),
            RetryOutcome::NeedsInstagramConfirmation => back()->withErrors([
                'instagram_comment_checked' => 'Check on Instagram that the first comment is missing, then confirm.',
            ]),
            RetryOutcome::CommentUnconfirmed => back()->withErrors([
                'post' => __('posts.errors.instagram.comment_unconfirmed'),
            ]),
            RetryOutcome::NotRetryable => back()->withErrors([
                'post' => 'Only a failed post with a channel can be retried.',
            ]),
            RetryOutcome::Changed => back()->withErrors([
                'post' => 'The post changed meanwhile; nothing was retried.',
            ]),
        };
    }

    private function queued(User $admin, Post $post, RecordAdminAction $recordAdminAction): RedirectResponse
    {
        $recordAdminAction->execute($admin, AdminActionType::RetryPost, $post, ['platform' => $post->platform?->value]);

        return back();
    }
}
