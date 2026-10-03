<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\Approval\RejectPost;
use App\Http\Requests\App\Post\ApprovePostRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PostApprovalController extends Controller
{
    public function approve(ApprovePostRequest $request, Post $post): RedirectResponse
    {
        ApprovePost::execute($post, $request->user(), $request->validated());

        return back();
    }

    public function reject(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('approve', $post);

        RejectPost::execute($post, $request->user());

        return back();
    }
}
