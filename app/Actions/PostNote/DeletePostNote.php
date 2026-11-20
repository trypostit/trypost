<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Events\PostNoteChanged;
use App\Models\Post;
use App\Models\PostNote;

final class DeletePostNote
{
    public static function execute(Post $post, PostNote $note): void
    {
        $note->delete();

        PostNoteChanged::dispatch($post->id, $post->workspace_id, 'deleted', $note->id);
    }
}
