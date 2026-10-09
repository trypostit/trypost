<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Post\UpdatePostRecurrence;
use App\Http\Requests\Api\Post\SetPostRecurrenceRequest;
use App\Http\Resources\Api\PostResource;
use App\Models\Post;

class PostRecurrenceController extends Controller
{
    public function update(SetPostRecurrenceRequest $request, Post $post): PostResource
    {
        UpdatePostRecurrence::execute($post, $request->validated());

        return $this->resource($post);
    }

    public function destroy(Post $post): PostResource
    {
        $this->authorize('update', $post);

        UpdatePostRecurrence::execute($post, null);

        return $this->resource($post);
    }

    private function resource(Post $post): PostResource
    {
        return new PostResource($post->fresh(['socialAccount', 'labels']));
    }
}
