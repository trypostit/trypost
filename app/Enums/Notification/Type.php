<?php

declare(strict_types=1);

namespace App\Enums\Notification;

enum Type: string
{
    case PostPublished = 'post_published';
    case PostFailed = 'post_failed';
    case PostPartiallyPublished = 'post_partially_published';
    case PostReady = 'post_ready';
    case PostManualPublishDue = 'post_manual_publish_due';
    case AccountDisconnected = 'account_disconnected';
    case InviteReceived = 'invite_received';
    case MemberJoined = 'member_joined';
    case MemberRemoved = 'member_removed';
    case MentionedInComment = 'mentioned_in_comment';
}
