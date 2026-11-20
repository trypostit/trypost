<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\PostNote\CreatePostNote;
use App\Actions\PostNote\DeletePostNote;
use App\Actions\PostNote\UpdatePostNote;
use App\Http\Requests\App\PostNote\StorePostNoteRequest;
use App\Http\Requests\App\PostNote\UpdatePostNoteRequest;
use App\Http\Resources\App\PostNoteResource;
use App\Models\Post;
use App\Models\PostNote;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PostNoteController extends Controller
{
    public function store(StorePostNoteRequest $request, Post $post): JsonResponse
    {
        $this->authorizeNotes($post);

        $note = CreatePostNote::execute($post, $request->user(), $request->validated());

        return PostNoteResource::make($note)->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Post $post, PostNote $note): PostNoteResource
    {
        abort_unless($note->post_id === $post->id, Response::HTTP_NOT_FOUND);

        $this->authorizeNotes($post);

        return PostNoteResource::make($note->load(PostNote::DISPLAY_RELATIONS));
    }

    public function update(UpdatePostNoteRequest $request, Post $post, PostNote $note): PostNoteResource
    {
        abort_unless($note->post_id === $post->id, Response::HTTP_NOT_FOUND);

        $this->authorizeNotes($post);
        $this->authorize('update', $note);

        return PostNoteResource::make(UpdatePostNote::execute($post, $note, $request->user(), $request->validated()));
    }

    public function destroy(Post $post, PostNote $note): JsonResponse
    {
        abort_unless($note->post_id === $post->id, Response::HTTP_NOT_FOUND);

        $this->authorizeNotes($post);
        $this->authorize('delete', $note);

        DeletePostNote::execute($post, $note);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    private function authorizeNotes(Post $post): void
    {
        $this->authorize('view', $post);
        $this->authorize('createPost', $post->workspace);
    }
}
