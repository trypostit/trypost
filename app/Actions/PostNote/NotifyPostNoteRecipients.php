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
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Mail\Mailable;

/**
 * Who hears about a note, among the members who see it (`$audience`, from
 * ListPostNoteAudience), never its author: the people it mentions (everyone with `@all`) get a
 * mention email, and on a new note the post's other participants (its author,
 * the member who asked for approval and everyone who wrote a note on it) get
 * the note email.
 */
final class NotifyPostNoteRecipients
{
    /**
     * @param  Collection<int, User>  $audience
     */
    public static function created(PostNote $note, User $author, Collection $audience): void
    {
        $post = $note->post;
        $recipients = $audience->reject(fn (User $member): bool => $member->is($author));
        $mentioned = self::mentionedIn($note->body, $recipients);

        $participantIds = $post->notes()
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->push($post->user_id, $post->approvalRequester()?->id);

        self::email($mentioned, fn (): Mailable => new PostNoteMentioned(note: $note, author: $author));
        self::email(
            $recipients->diff($mentioned)->filter(fn (User $member): bool => $participantIds->contains($member->id)),
            fn (): Mailable => new PostNoteAdded(note: $note, author: $author),
        );
    }

    /**
     * An edit only emails the people it adds as mentions.
     *
     * @param  Collection<int, User>  $audience
     */
    public static function edited(PostNote $note, User $author, string $previousBody, Collection $audience): void
    {
        $recipients = $audience->reject(fn (User $member): bool => $member->is($author));
        $newlyMentioned = self::mentionedIn($note->body, $recipients)->diff(self::mentionedIn($previousBody, $recipients));

        self::email($newlyMentioned, fn (): Mailable => new PostNoteMentioned(note: $note, author: $author));
    }

    /**
     * @param  Collection<int, User>  $audience
     * @return Collection<int, User>
     */
    private static function mentionedIn(string $body, Collection $audience): Collection
    {
        if (PostNoteMentions::mentionsEveryone($body)) {
            return $audience;
        }

        $ids = PostNoteMentions::extract($body);

        return $audience->filter(fn (User $member): bool => $ids->contains($member->id));
    }

    /**
     * @param  Collection<int, User>  $members
     * @param  Closure(): Mailable  $mailable
     */
    private static function email(Collection $members, Closure $mailable): void
    {
        $members->each(fn (User $member) => SendNotification::dispatch(
            user: $member,
            type: Type::PostNoteAdded,
            mailable: $mailable(),
        ));
    }
}
