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
        $audience = ListPostNoteAudience::execute($post);

        $note = $post->notes()->create([
            'user_id' => $author->id,
            'body' => PostNoteMentions::withoutMentionsOutside((string) data_get($data, 'body'), $audience),
        ]);

        $note->mentions()->sync(PostNoteMentions::extract($note->body));
        $note->load(PostNote::DISPLAY_RELATIONS);

        NotifyPostNoteRecipients::created($note, $author, $audience);
        PostNoteChanged::dispatch($post->id, $post->workspace_id, 'created');

        return $note;
    }
}
