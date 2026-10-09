<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Enums\Notification\Type;
use App\Jobs\SendNotification;
use App\Mail\PostApprovalRequested;
use App\Models\Post;
use App\Models\User;
use Illuminate\Support\Collection;

final class NotifyApprovalRequested
{
    /**
     * One email per post for every approver: posts created together for
     * several channels are separate posts, each approved on its own. Never
     * emails the requester.
     *
     * @param  Collection<int, Post>  $posts
     */
    public static function execute(Collection $posts, User $requester): void
    {
        $workspace = $posts->first()?->workspace;

        if (blank($workspace)) {
            return;
        }

        $approvers = $workspace->approvers()->reject(fn (User $approver): bool => $approver->id === $requester->id);

        $posts->each(fn (Post $post) => $approvers->each(fn (User $approver) => SendNotification::dispatch(
            $approver,
            Type::Collaboration,
            new PostApprovalRequested($post->id, $requester, $approver),
        )->afterCommit()));
    }
}
