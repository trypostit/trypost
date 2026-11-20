<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Events\PostNoteChanged;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\User;

final class RemovePostNoteReaction
{
    /**
     * Take back the user's reaction with that emoji, if they had one.
     */
    public static function execute(Post $post, PostNote $note, User $user, string $emoji): PostNote
    {
        $removed = $note->reactions()->where('user_id', $user->id)->where('emoji', $emoji)->delete();

        PostNoteChanged::dispatchIf($removed > 0, $post->id, $post->workspace_id, 'updated', $note->id);

        return $note->load(PostNote::DISPLAY_RELATIONS);
    }
}
