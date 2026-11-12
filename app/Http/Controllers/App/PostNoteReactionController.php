<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\PostNote\AddPostNoteReaction;
use App\Actions\PostNote\RemovePostNoteReaction;
use App\Http\Requests\App\PostNoteReaction\DestroyPostNoteReactionRequest;
use App\Http\Requests\App\PostNoteReaction\UpdatePostNoteReactionRequest;
use App\Http\Resources\App\PostNoteResource;
use App\Models\Post;
use App\Models\PostNote;
use Symfony\Component\HttpFoundation\Response;

class PostNoteReactionController extends Controller
{
    public function update(UpdatePostNoteReactionRequest $request, Post $post, PostNote $note): PostNoteResource
    {
        $this->authorizeReactions($post, $note);

        return PostNoteResource::make(AddPostNoteReaction::execute($note, $request->user(), $request->validated('emoji')));
    }

    public function destroy(DestroyPostNoteReactionRequest $request, Post $post, PostNote $note): PostNoteResource
    {
        $this->authorizeReactions($post, $note);

        return PostNoteResource::make(RemovePostNoteReaction::execute($note, $request->user(), $request->validated('emoji')));
    }

    private function authorizeReactions(Post $post, PostNote $note): void
    {
        abort_unless($note->post_id === $post->id, Response::HTTP_NOT_FOUND);

        $this->authorize('view', $post);
        $this->authorize('createPost', $post->workspace);
    }
}
