<?php

declare(strict_types=1);

namespace App\Actions\PostNote;

use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

final class ListPostNoteAudience
{
    /**
     * The members who see the post's notes: every member of its workspace,
     * and on a pending request only the approvers and the member who asked.
     * They are the people a note may mention and email, and the mention card
     * reads them, so the viewer is in the list too. Sorted by name in PHP,
     * ignoring case and accents (a name `Str::ascii` empties, like 山田, by its
     * own text, then by id), so PostgreSQL and MySQL give the same order.
     *
     * @return Collection<int, User>
     */
    public static function execute(Post $post): Collection
    {
        $workspace = $post->workspace;

        $isPending = $post->status === Status::PendingApproval;
        $requesterId = $isPending ? $post->approvalRequester()?->id : null;

        return $workspace->members()
            ->with(['account', 'avatarMedia'])
            ->get()
            ->sortBy(fn (User $member): array => [Str::lower(Str::ascii($member->name)), Str::lower($member->name), $member->id])
            ->when($isPending, fn (Collection $members): Collection => $members->filter(
                fn (User $member): bool => $member->id === $requesterId || $member->publishesDirectlyThrough($workspace, $member->pivot),
            ))
            ->values();
    }
}
