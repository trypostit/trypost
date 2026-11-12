<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Events\PostNoteChanged;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\User;
use App\Support\PostNoteMentions;

final class UpdatePostNote
{
    /**
     * @param  array{body?: string}  $data
     */
    public static function execute(Post $post, PostNote $note, User $author, array $data): PostNote
    {
        $previousBody = $note->body;

        $note->update(['body' => PostNoteMentions::withCurrentNames((string) data_get($data, 'body'), ListPostNoteAudience::execute($post))]);

        NotifyPostNoteRecipients::edited($note, $author, $previousBody);

        PostNoteChanged::dispatch($post->id, $post->workspace_id, 'updated');

        return $note->load(['user.avatarMedia', 'reactions.user']);
    }
}
