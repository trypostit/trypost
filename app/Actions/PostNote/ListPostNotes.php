<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Models\Post;
use App\Models\PostNote;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;

final class ListPostNotes
{
    /**
     * @return LengthAwarePaginator<int, PostNote>
     */
    public static function execute(Post $post, ?int $page = null): LengthAwarePaginator
    {
        return $post->notes()
            ->with(PostNote::DISPLAY_RELATIONS)
            ->latest()
            ->latest('id')
            ->paginate((int) config('app.pagination.default'), page: $page);
    }

    /**
     * The thread the publish page shows, newest first, a page at a time by
     * cursor: each older page starts after the last note loaded, so notes added
     * or deleted while the thread is open never shift it (see AGENTS.md,
     * "Pagination").
     *
     * @return CursorPaginator<int, PostNote>
     */
    public static function thread(Post $post, string $cursorName): CursorPaginator
    {
        return $post->notes()
            ->with(PostNote::DISPLAY_RELATIONS)
            ->latest()
            ->latest('id')
            ->cursorPaginate((int) config('app.pagination.default'), cursorName: $cursorName);
    }
}
