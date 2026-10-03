<?php

declare(strict_types=1);

namespace App\Actions\Post\Approval;

use App\Enums\Post\ApprovalDecision;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\PostApproval;

class RejectPost
{
    /**
     * Sends a pending post back to drafts. Its requested time stays on the draft.
     */
    public static function execute(Post $post, User $approver): Post
    {
        return PostApproval::whilePending($post, function () use ($post, $approver): Post {
            $requester = $post->approvalRequester();

            $post->update([
                'status' => PostStatus::Draft,
                'schedule_mode' => null,
                ...PostApproval::transition(PostStatus::PendingApproval, PostStatus::Draft, $approver),
            ]);

            NotifyApprovalDecision::execute($post, ApprovalDecision::Rejected, $approver, $requester);

            return $post;
        });
    }
}
