<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Shared data for the approval emails, which are sent one per post: the post,
 * its channel, an excerpt and a time in the recipient's zone.
 */
class ApprovalEmailPost
{
    public static function find(string $postId): ?Post
    {
        return Post::query()->with(['workspace', 'socialAccount'])->find($postId);
    }

    public static function excerpt(?Post $post): string
    {
        return PostExcerpt::from($post?->content, 280);
    }

    /**
     * The post itself when it has a channel to show, null for a draft without one.
     */
    public static function channel(?Post $post): ?Post
    {
        return $post?->hasDestination() ? $post : null;
    }

    /**
     * When the approved post goes out; null means it is publishing now.
     */
    public static function goesOutAt(?Post $post, User $recipient): ?string
    {
        return $post?->status === PostStatus::Scheduled ? self::time($post->scheduled_at, $recipient) : null;
    }

    public static function time(?CarbonInterface $at, User $recipient): ?string
    {
        if (blank($at)) {
            return null;
        }

        $dateTime = RecipientTime::dateTime($at, $recipient);
        $timezone = RecipientTime::timezone($recipient);

        return "{$dateTime} ({$timezone})";
    }
}
