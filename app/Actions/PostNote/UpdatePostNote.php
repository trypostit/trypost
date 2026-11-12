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
        $audience = ListPostNoteAudience::execute($post);

        $note->update(['body' => PostNoteMentions::withoutMentionsOutside((string) data_get($data, 'body'), $audience)]);
        $note->mentions()->sync(PostNoteMentions::extract($note->body));

        NotifyPostNoteRecipients::edited($note, $author, $previousBody, $audience);

        PostNoteChanged::dispatch($post->id, $post->workspace_id, 'updated');

        return $note->load(PostNote::DISPLAY_RELATIONS);
    }
}
