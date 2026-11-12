<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\PostNote\AddPostNoteReaction;
use App\Actions\PostNote\RemovePostNoteReaction;
use App\Http\Requests\Api\PostNoteReaction\DestroyPostNoteReactionRequest;
use App\Http\Requests\Api\PostNoteReaction\UpdatePostNoteReactionRequest;
use App\Http\Resources\Api\PostNoteResource;
use App\Models\Post;
use App\Models\PostNote;

class PostNoteReactionController extends Controller
{
    public function update(UpdatePostNoteReactionRequest $request, Post $post, PostNote $note): PostNoteResource
    {
        return new PostNoteResource(AddPostNoteReaction::execute($note, $request->user(), $request->validated('emoji')));
    }

    public function destroy(DestroyPostNoteReactionRequest $request, Post $post, PostNote $note): PostNoteResource
    {
        return new PostNoteResource(RemovePostNoteReaction::execute($note, $request->user(), $request->validated('emoji')));
    }
}
