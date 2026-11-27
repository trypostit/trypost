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

    /** The post changed while the retry was being prepared; nothing was queued. */
    case Changed = 'changed';
}
