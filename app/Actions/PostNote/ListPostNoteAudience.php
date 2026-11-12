<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

final class ListPostNoteAudience
{
    /**
     * The members who see the post's notes, by name: every member of its
     * workspace, and on a pending request only the approvers and the member
     * who asked. They are the people a note may mention and email; `$except`
     * leaves one out (nobody mentions themselves).
     *
     * @return Collection<int, User>
     */
    public static function execute(Post $post, ?User $except = null): Collection
    {
        $workspace = $post->workspace;

        if ($workspace === null) {
            return new Collection;
        }

        $isPending = $post->status === Status::PendingApproval;
        $requesterId = $isPending ? $post->approvalRequester()?->id : null;

        return $workspace->members()
            ->with(['account', 'avatarMedia'])
            ->orderBy('users.name')
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->id))
            ->get()
            ->when($isPending, fn (Collection $members): Collection => $members->filter(
                fn (User $member): bool => $member->id === $requesterId || $member->publishesDirectlyThrough($workspace, $member->pivot),
            ))
            ->values();
    }
}
