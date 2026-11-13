<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Http\Resources\App\PostNoteResource;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\RequestIds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\ScrollProp;

/**
 * The notes thread the publish page shows: the post whose notes are
 * open (`?notes=`), the note a link points at (`?note=`, only while it is
 * still on that post, so a stale link never makes the app load every page
 * looking for it) and the notes
 * themselves, newest first and a page at a time by cursor for
 * `<InfiniteScroll reverse>`, with their `meta.total`.
 * Opening, closing and every change to the thread reload only these props.
 */
final class BuildPostNotesProps
{
    public const CURSOR_NAME = 'notes_cursor';

    /**
     * @return array{openPostNotesId: string|null, highlightNoteId: string|null, postNotes: ScrollProp|null}
     */
    public static function handle(Request $request, Workspace $workspace): array
    {
        $postId = RequestIds::uuid($request->query('notes'));
        $post = transform($postId, fn (string $id): ?Post => $workspace->posts()->find($id));
        $canRead = filled($post)
            && Gate::forUser($request->user())->allows('view', $post)
            && Gate::forUser($request->user())->allows('createPost', $workspace);

        $noteId = RequestIds::uuid($request->query('note'));

        return [
            'openPostNotesId' => $canRead ? $postId : null,
            'highlightNoteId' => $canRead && filled($noteId) && $post->notes()->whereKey($noteId)->exists() ? $noteId : null,
            'postNotes' => $canRead
                ? Inertia::scroll(fn () => PostNoteResource::collection(ListPostNotes::thread($post, self::CURSOR_NAME))
                    ->additional(['meta' => ['total' => $post->notes()->count()]]))
                : null,
        ];
    }
}
