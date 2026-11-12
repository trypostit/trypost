<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class ListPostNoteAudience
{
    /**
     * The members who see the post's notes: every member of its workspace,
     * and on a pending request only the approvers and the member who asked.
     * They are the people a note may mention and email; `$except` leaves one
     * out (nobody mentions themselves). Sorted by name in PHP, ignoring case
     * and accents, so PostgreSQL and MySQL give the same order.
     *
     * @return Collection<int, User>
     */
    public static function execute(Post $post, ?User $except = null): Collection
    {
        $workspace = $post->workspace;

        if (blank($workspace)) {
            return new Collection;
        }

        $isPending = $post->status === Status::PendingApproval;
        $requesterId = $isPending ? $post->approvalRequester()?->id : null;

        return $workspace->members()
            ->with(['account', 'avatarMedia'])
            ->when(filled($except), fn (Builder $query): Builder => $query->whereKeyNot($except->id))
            ->get()
            ->sortBy(fn (User $member): string => Str::lower(Str::ascii($member->name)))
            ->when($isPending, fn (Collection $members): Collection => $members->filter(
                fn (User $member): bool => $member->id === $requesterId || $member->publishesDirectlyThrough($workspace, $member->pivot),
            ))
            ->values();
    }
}
