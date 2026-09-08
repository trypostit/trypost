<?php

declare(strict_types=1);

namespace App\Enums\Webhook;

use App\Enums\Post\Status as PostStatus;

enum EventType: string
{
    case PostCreated = 'post.created';
    case PostScheduled = 'post.scheduled';
    case PostUnscheduled = 'post.unscheduled';
    case PostPublished = 'post.published';
    case PostPartiallyPublished = 'post.partially_published';
    case PostFailed = 'post.failed';
    case PostDeleted = 'post.deleted';

    public static function fromPostStatus(PostStatus $status, ?PostStatus $previous = null): ?self
    {
        return match ($status) {
            PostStatus::Scheduled => self::PostScheduled,
            PostStatus::Published => self::PostPublished,
            PostStatus::PartiallyPublished => self::PostPartiallyPublished,
            PostStatus::Failed => self::PostFailed,
            PostStatus::Draft => $previous === PostStatus::Scheduled ? self::PostUnscheduled : null,
            default => null,
        };
    }
}
