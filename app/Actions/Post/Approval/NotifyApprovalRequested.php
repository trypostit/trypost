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
     * One email per approver for one request; the posts created together for
     * several channels are listed in the same email. Never emails the requester.
     *
     * @param  Collection<int, Post>  $posts
     */
    public static function execute(Collection $posts, User $requester): void
    {
        $workspace = $posts->first()?->workspace;

        if ($workspace === null) {
            return;
        }

        $postIds = $posts->pluck('id')->values()->all();

        $workspace->approvers()
            ->reject(fn (User $approver): bool => $approver->id === $requester->id)
            ->each(fn (User $approver) => SendNotification::dispatch(
                $approver,
                Type::Collaboration,
                new PostApprovalRequested($postIds, $requester, $approver),
            )->afterCommit());
    }
}
