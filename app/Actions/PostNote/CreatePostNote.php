<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Events\PostNoteChanged;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\User;

final class CreatePostNote
{
    /**
     * @param  array{body?: string}  $data
     */
    public static function execute(Post $post, User $author, array $data): PostNote
    {
        $note = $post->notes()->create([
            'user_id' => $author->id,
            'body' => data_get($data, 'body'),
        ]);

        $note->load(['user.avatarMedia', 'reactions.user']);

        NotifyPostNoteRecipients::created($note);
        PostNoteChanged::dispatch($post->id, $post->workspace_id, 'created');

        return $note;
    }
}
