<?php

declare(strict_types=1);

namespace App\Enums\Webhook;

use App\Enums\Post\PublishStatus;
use App\Enums\Post\Status as PostStatus;

enum EventType: string
{
    case PostCreated = 'post.created';
    case PostScheduled = 'post.scheduled';
    case PostUnscheduled = 'post.unscheduled';
    case PostPublished = 'post.published';
    case PostDeletedOnNetwork = 'post.deleted_on_network';
    case PostRestored = 'post.restored';
    case PostFailed = 'post.failed';
    case PostDeleted = 'post.deleted';

    public static function fromPostStatus(PostStatus $status, ?PostStatus $previous = null): ?self
    {
        return match ($status) {
            PostStatus::Scheduled => self::PostScheduled,
            PostStatus::Published => self::PostPublished,
            PostStatus::Failed => self::PostFailed,
            PostStatus::Draft => $previous === PostStatus::Scheduled ? self::PostUnscheduled : null,
            PostStatus::PendingApproval => $previous === PostStatus::Scheduled ? self::PostUnscheduled : null,
            default => null,
        };
    }

    /**
     * The network side of a post changed: deleted there, or listed there again.
     */
    public static function fromPublishStatus(PublishStatus $status, ?PublishStatus $previous = null): ?self
    {
        return match (true) {
            $status === PublishStatus::Deleted => self::PostDeletedOnNetwork,
            $status === PublishStatus::Published && $previous === PublishStatus::Deleted => self::PostRestored,
            default => null,
        };
    }
}
