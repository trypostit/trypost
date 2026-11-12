<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Enums\Notification\Type;
use App\Jobs\SendNotification;
use App\Mail\PostNoteAdded;
use App\Mail\PostNoteMentioned;
use App\Models\PostNote;
use App\Models\User;
use App\Support\PostNoteMentions;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who hears about a note, among the members who see it (ListPostNoteAudience),
 * never its author: the people it mentions get a mention email, and on a new
 * note the post's participants (its author, the member who asked for approval
 * and everyone who wrote a note on it) get the note email.
 */
final class NotifyPostNoteRecipients
{
    public static function created(PostNote $note): void
    {
        $author = $note->user;

        if (! $author instanceof User) {
            return;
        }

        $audience = ListPostNoteAudience::execute($note->post);
        $mentioned = self::mention($note, $author, $audience, PostNoteMentions::extract($note->body));

        $post = $note->post;
        $participantIds = $post->notes()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->push($post->user_id, $post->approvalRequester()?->id)
            ->filter()
            ->diff([$author->id, ...$mentioned->modelKeys()]);

        $audience
            ->filter(fn (User $member): bool => $participantIds->contains($member->id))
            ->each(fn (User $member) => SendNotification::dispatch(
                user: $member,
                type: Type::PostNoteAdded,
                mailable: new PostNoteAdded(note: $note, author: $author),
            ));
    }

    /**
     * An edit only emails the people it mentions for the first time.
     */
    public static function edited(PostNote $note, string $previousBody): void
    {
        $author = $note->user;
        $newIds = array_diff(PostNoteMentions::extract($note->body), PostNoteMentions::extract($previousBody));

        if (! $author instanceof User || blank($newIds)) {
            return;
        }

        self::mention($note, $author, ListPostNoteAudience::execute($note->post), $newIds);
    }

    /**
     * @param  Collection<int, User>  $audience
     * @param  array<int, string>  $ids
     * @return Collection<int, User>
     */
    private static function mention(PostNote $note, User $author, Collection $audience, array $ids): Collection
    {
        $mentioned = $audience
            ->filter(fn (User $member): bool => $member->id !== $author->id && in_array($member->id, $ids, true))
            ->values();

        $mentioned->each(fn (User $member) => SendNotification::dispatch(
            user: $member,
            type: Type::PostNoteAdded,
            mailable: new PostNoteMentioned(note: $note, author: $author),
        ));

        return $mentioned;
    }
}
