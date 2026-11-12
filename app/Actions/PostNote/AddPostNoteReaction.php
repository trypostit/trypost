<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Events\PostNoteChanged;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\User;

final class AddPostNoteReaction
{
    /**
     * React to a note with an emoji. Reacting again with the same emoji
     * changes nothing.
     */
    public static function execute(Post $post, PostNote $note, User $user, string $emoji): PostNote
    {
        $reaction = $note->reactions()->createOrFirst(['user_id' => $user->id, 'emoji' => $emoji]);

        if ($reaction->wasRecentlyCreated) {
            PostNoteChanged::dispatch($post->id, $post->workspace_id, 'updated');
        }

        return $note->load(PostNote::DISPLAY_RELATIONS);
    }
}
