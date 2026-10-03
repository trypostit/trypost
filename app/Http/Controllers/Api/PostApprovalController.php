<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Post\Approval\ApprovePost;
use App\Actions\Post\Approval\RejectPost;
use App\Http\Requests\Api\Post\ApprovePostRequest;
use App\Http\Resources\Api\PostResource;
use App\Models\Post;
use Illuminate\Http\Request;

class PostApprovalController extends Controller
{
    public function approve(ApprovePostRequest $request, Post $post): PostResource
    {
        /** @var Post $approved */
        $approved = data_get(ApprovePost::execute($post, $request->user(), $request->validated()), 'post');
        $approved->load(['postPlatforms.socialAccount', 'labels']);

        return new PostResource($approved);
    }

    public function reject(Request $request, Post $post): PostResource
    {
        $this->authorize('approve', $post);

        $rejected = RejectPost::execute($post, $request->user());
        $rejected->load(['postPlatforms.socialAccount', 'labels']);

        return new PostResource($rejected);
    }
}
