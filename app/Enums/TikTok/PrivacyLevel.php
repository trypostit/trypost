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
        return array_map(fn (self $level) => $level->value, self::cases());
    }

    /**
     * Keep only values the Content Posting API accepts, in the given order.
     * Unknown creator_info options are dropped so they never reach the editor
     * or a publish payload.
     *
     * @param  iterable<mixed>  $options
     * @return list<string>
     */
    public static function knownValues(iterable $options): array
    {
        $values = [];

        foreach ($options as $option) {
            $level = self::tryFrom((string) $option);

            if ($level instanceof self) {
                $values[] = $level->value;
            }
        }

        return $values;
    }

    public function allowsBrandedContent(): bool
    {
        return $this !== self::SelfOnly;
    }
}
