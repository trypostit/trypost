<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Models\Post;
use App\Models\PostNote;
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
            ->paginate((int) config('app.pagination.default'), page: $page);
    }
}
