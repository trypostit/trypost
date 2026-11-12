<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Events\PostNoteChanged;
use App\Models\Post;
use App\Models\PostNote;

final class UpdatePostNote
{
    /**
     * @param  array{body?: string}  $data
     */
    public static function execute(Post $post, PostNote $note, array $data): PostNote
    {
        $previousBody = $note->body;

        $note->update(['body' => data_get($data, 'body')]);

        NotifyPostNoteRecipients::edited($note, $previousBody);

        PostNoteChanged::dispatch($post->id, $post->workspace_id, 'updated');

        return $note->load(['user.avatarMedia', 'reactions.user']);
    }
}
