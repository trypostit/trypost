<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Events\PostNoteChanged;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\User;
use App\Support\PostNoteMentions;

final class CreatePostNote
{
    /**
     * @param  array{body?: string}  $data
     */
    public static function execute(Post $post, User $author, array $data): PostNote
    {
        $note = $post->notes()->create([
            'user_id' => $author->id,
            'body' => PostNoteMentions::withCurrentNames((string) data_get($data, 'body'), ListPostNoteAudience::execute($post)),
        ]);

        $note->load(['user.avatarMedia', 'reactions.user']);

        NotifyPostNoteRecipients::created($note, $author);
        PostNoteChanged::dispatch($post->id, $post->workspace_id, 'created');

        return $note;
    }
}
