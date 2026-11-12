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
 * open (`?notes=`), the note a link points at (`?note=`) and the notes
 * themselves, newest first and a page at a time for `<InfiniteScroll reverse>`.
 * Opening, closing and every change to the thread reload only these props.
 */
final class BuildPostNotesProps
{
    public const PAGE_NAME = 'notes_page';

    /**
     * @return array{openPostNotesId: string|null, highlightNoteId: string|null, postNotes: ScrollProp|null}
     */
    public static function handle(Request $request, Workspace $workspace): array
    {
        $postId = RequestIds::uuid($request->query('notes'));
        $post = transform($postId, fn (string $id): ?Post => $workspace->posts()->find($id));
        $canRead = $post !== null
            && Gate::forUser($request->user())->allows('view', $post)
            && Gate::forUser($request->user())->allows('createPost', $workspace);

        return [
            'openPostNotesId' => $postId,
            'highlightNoteId' => RequestIds::uuid($request->query('note')),
            'postNotes' => $canRead
                ? Inertia::scroll(fn () => PostNoteResource::collection(ListPostNotes::execute($post, pageName: self::PAGE_NAME)))
                : null,
        ];
    }
}
