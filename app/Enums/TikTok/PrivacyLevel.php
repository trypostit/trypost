<?php

declare(strict_types=1);

namespace App\Enums\TikTok;

/**
 * TikTok `post_info.privacy_level` values from the Content Posting API.
 *
 * @see https://developers.tiktok.com/doc/content-posting-api-reference-direct-post
 */
enum PrivacyLevel: string
{
    case PublicToEveryone = 'PUBLIC_TO_EVERYONE';
    case MutualFollowFriends = 'MUTUAL_FOLLOW_FRIENDS';
    case FollowerOfCreator = 'FOLLOWER_OF_CREATOR';
    case SelfOnly = 'SELF_ONLY';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
