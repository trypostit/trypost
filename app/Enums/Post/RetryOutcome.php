<?php

declare(strict_types=1);

namespace App\Enums\Post;

enum RetryOutcome: string
{
    /** A publish attempt was queued. */
    case Queued = 'queued';

    /** Only a failed post with a channel can be retried. */
    case NotRetryable = 'not_retryable';

    /** The Instagram first comment may be live and the post itself is not: nothing can be retried safely. */
    case CommentUnconfirmed = 'comment_unconfirmed';

    /** The post is live but its first comment may be too: someone must check Instagram first. */
    case NeedsInstagramConfirmation = 'needs_instagram_confirmation';

    /** The owner of the post's account is blocked: their posts wait until they are unblocked. */
    case OwnerBlocked = 'owner_blocked';

    /** The post changed while the retry was being prepared; nothing was queued. */
    case Changed = 'changed';

    /** The form field the backoffice shows the outcome under. */
    public function errorField(): string
    {
        return match ($this) {
            self::NeedsInstagramConfirmation => 'instagram_comment_checked',
            self::Queued,
            self::NotRetryable,
            self::CommentUnconfirmed,
            self::OwnerBlocked,
            self::Changed => 'post',
        };
    }

    /** What the backoffice tells the admin who retried. */
    public function message(): string
    {
        return match ($this) {
            self::Queued => 'A publish attempt was queued.',
            self::NotRetryable => 'Only a failed post with a channel can be retried.',
            self::CommentUnconfirmed => __('posts.errors.instagram.comment_unconfirmed'),
            self::NeedsInstagramConfirmation => 'Check on Instagram that the first comment is missing, then confirm.',
            self::OwnerBlocked => 'The account owner is blocked: unblock them to retry this post.',
            self::Changed => 'The post changed meanwhile; nothing was retried.',
        };
    }
}
