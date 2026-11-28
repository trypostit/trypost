<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Post\RetryFailedPostFromAdmin;
use App\Enums\Post\RetryOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\RetryPostRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;

class PostRetryController extends Controller
{
    public function store(RetryPostRequest $request, Post $post, RetryFailedPostFromAdmin $retryPost): RedirectResponse
    {
        $outcome = $retryPost->execute($request->user(), $post, $request->instagramCommentChecked());

        if ($outcome === RetryOutcome::Queued) {
            return back();
        }

        return back()->withErrors([$outcome->errorField() => $outcome->message()]);
    }
}
