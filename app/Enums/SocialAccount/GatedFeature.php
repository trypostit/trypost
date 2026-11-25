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
    case PrivatePostRead = 'private_post_read';
    case VideoCover = 'video_cover';

    /**
     * Whether a post uses the feature, so a channel lacking its scope cannot
     * schedule or publish it. Mirrored by `requiresFeatureToPublish()` in
     * `lib/channelPermissions.ts`: every post publishes, a post with replies
     * uses them, a Reel whose video has a cover image uses the video cover,
     * analytics are never part of a post.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function isRequiredToPublish(?array $meta, ?ContentType $type = null, bool $hasVideoCover = false): bool
    {
        return match ($this) {
            self::Publish => true,
            self::ThreadReplies => filled(ThreadReplies::of($meta)),
            self::VideoCover => self::usesVideoCover($type, $hasVideoCover),
            self::Followers, self::PostInsights, self::PostInsightsExtended, self::PostHistory, self::PrivatePostRead => false,
        };
    }

    /** A cover TryPost sets after publishing, which only a type like the Facebook Reel takes. */
    private static function usesVideoCover(?ContentType $type, bool $hasVideoCover): bool
    {
        if (! $hasVideoCover) {
            return false;
        }

        return $type?->setsCoverAfterPublish() === true;
    }

    /**
     * Where a refusal for the feature lands among a post's fields: the channel
     * itself for publishing, the replies for a thread. Analytics are never part
     * of a post, so they never land anywhere but on the channel.
     */
    public function postValidationField(): string
    {
        return match ($this) {
            self::ThreadReplies => 'meta.thread_replies',
            self::VideoCover => 'media',
            self::Publish, self::Followers, self::PostInsights, self::PostInsightsExtended, self::PostHistory, self::PrivatePostRead => 'social_account_id',
        };
    }

    /**
     * A read TryPost skips quietly without its scope (Facebook `read_insights`,
     * YouTube Analytics, Mastodon private posts): the data is only thinner, so the channel is never
     * told to reconnect for it.
     */
    public function isOptionalRead(): bool
    {
        return match ($this) {
            self::PostInsightsExtended, self::PrivatePostRead => true,
            self::Publish, self::ThreadReplies, self::Followers, self::PostInsights, self::PostHistory, self::VideoCover => false,
        };
    }

    /**
     * The lang group of the copy a channel lacking the feature is shown
     * (`message`, `member_message`), unless its network sets its own. The
     * analytics features share one: to the user they are all insights.
     */
    public function translationGroup(): string
    {
        return match ($this) {
            self::Publish => 'channels.permissions.publish',
            self::ThreadReplies => 'channels.permissions.thread_replies',
            self::VideoCover => 'channels.permissions.video_cover',
            self::Followers, self::PostInsights, self::PostInsightsExtended, self::PostHistory, self::PrivatePostRead => 'channels.permissions.insights',
        };
    }
}
