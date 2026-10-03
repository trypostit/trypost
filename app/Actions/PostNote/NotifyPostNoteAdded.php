<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Enums\Notification\Type;
use App\Jobs\SendNotification;
use App\Mail\PostNoteAdded;
use App\Models\PostNote;
use App\Models\User;

final class NotifyPostNoteAdded
{
    /**
     * Email every member of the post's workspace, except the note's author.
     */
    public static function execute(PostNote $note): void
    {
        $author = $note->user;
        $workspace = $note->post?->workspace;

        if (! $author instanceof User || ! $workspace) {
            return;
        }

        $workspace->members()
            ->where('users.id', '!=', $author->id)
            ->get()
            ->each(fn (User $member) => SendNotification::dispatch(
                user: $member,
                type: Type::PostNoteAdded,
                mailable: new PostNoteAdded(note: $note, author: $author),
            ));
    }
}
