<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\RetryPost;
use App\Enums\Post\RetryOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\RetryPostRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;

class PostRetryController extends Controller
{
    public function store(RetryPostRequest $request, Post $post, RetryPost $retryPost): RedirectResponse
    {
        $outcome = $retryPost->execute($request->user(), $post, $request->boolean('instagram_comment_checked'));

        return match ($outcome) {
            RetryOutcome::Queued => back(),
            RetryOutcome::NeedsInstagramConfirmation => back()->withErrors([
                'instagram_comment_checked' => 'Check on Instagram that the first comment is missing, then confirm.',
            ]),
            RetryOutcome::CommentUnconfirmed => back()->withErrors([
                'post' => __('posts.errors.instagram.comment_unconfirmed'),
            ]),
            RetryOutcome::NotRetryable => back()->withErrors([
                'post' => 'Only a failed post with a channel can be retried.',
            ]),
            RetryOutcome::OwnerBlocked => back()->withErrors([
                'post' => 'The account owner is blocked: unblock them to retry this post.',
            ]),
            RetryOutcome::Changed => back()->withErrors([
                'post' => 'The post changed meanwhile; nothing was retried.',
            ]),
        };
    }
}
