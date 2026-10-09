<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Enums\Notification\Type;
use App\Enums\Post\ApprovalDecision;
use App\Jobs\SendNotification;
use App\Mail\PostApproved;
use App\Mail\PostRejected;
use App\Models\Post;
use App\Models\User;

final class NotifyApprovalDecision
{
    /**
     * Emails the member who asked for approval (see Post::approvalRequester())
     * about one post, once the decision commits. Deciding your own request
     * sends nothing.
     */
    public static function execute(Post $post, ApprovalDecision $decision, User $approver, ?User $requester): void
    {
        if (blank($requester) || $requester->id === $approver->id) {
            return;
        }

        SendNotification::dispatch(
            $requester,
            Type::Collaboration,
            $decision === ApprovalDecision::Approved
                ? new PostApproved($post->id, $approver, $requester)
                : new PostRejected($post->id, $approver),
        )->afterCommit();
    }
}
