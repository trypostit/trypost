<?php

declare(strict_types=1);

namespace App\Enums\SocialAccount;

use App\Enums\PostPlatform\ContentType;
use App\Support\ThreadReplies;

/**
 * What TryPost does on a channel that needs the account to have granted a
 * scope. Which scopes, per network, is that network's `permissions` setting in
 * `config/trypost.php`, read by `App\Support\ChannelPermissions`.
 */
enum GatedFeature: string
{
    case Publish = 'publish';
    case ThreadReplies = 'thread_replies';
    case Followers = 'followers';
    case PostInsights = 'post_insights';
    case PostInsightsExtended = 'post_insights_extended';
    case PostHistory = 'post_history';

    /**
     * Whether a post uses the feature, so a channel lacking its scope cannot
     * schedule or publish it. Mirrored by `featureIsUsedBy()` in
     * `lib/channelPermissions.ts`. Analytics features are never part of a post.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function isUsedBy(?ContentType $type, ?array $meta): bool
    {
        return match ($this) {
            self::Publish => true,
            self::ThreadReplies => filled(ThreadReplies::of($meta)),
            self::Followers, self::PostInsights, self::PostInsightsExtended, self::PostHistory => false,
        };
    }

    /**
     * The lang group of the copy a channel lacking the feature is shown
     * (`message`, `member_message`), unless its network sets its own. The
     * analytics features share one: to the user they are all insights.
     */
    public function copyGroup(): string
    {
        return match ($this) {
            self::Publish => 'channels.permissions.publish',
            self::ThreadReplies => 'channels.permissions.thread_replies',
            self::Followers, self::PostInsights, self::PostInsightsExtended, self::PostHistory => 'channels.permissions.insights',
        };
    }
}
