<?php

declare(strict_types=1);

namespace App\Dto\Inbox;

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use InvalidArgumentException;

/**
 * Where a Meta channel reads and sends its messages: Instagram Login talks
 * to graph.instagram.com as the account, Instagram through Facebook and
 * Facebook Pages talk to the Page on graph.facebook.com.
 */
final readonly class MetaMessagingEndpoint
{
    public function __construct(
        public string $graphApi,
        public string $node,
        public string $platform,
        public string $ownId,
        public bool $isInstagramLogin,
    ) {}

    public static function for(SocialAccount $account): self
    {
        return match ($account->platform) {
            Platform::Instagram => new self(
                (string) config('trypost.platforms.instagram.graph_api'),
                'me',
                'instagram',
                (string) (data_get($account->meta, 'ig_user_id') ?: $account->platform_user_id),
                true,
            ),
            Platform::InstagramFacebook => new self(
                (string) config('trypost.platforms.instagram-facebook.graph_api'),
                (string) (data_get($account->meta, 'page_id') ?: throw new InvalidArgumentException('This Instagram account has no linked Facebook Page.')),
                'instagram',
                (string) $account->platform_user_id,
                false,
            ),
            Platform::Facebook => new self(
                (string) config('trypost.platforms.facebook.graph_api'),
                (string) $account->platform_user_id,
                'messenger',
                (string) $account->platform_user_id,
                false,
            ),
            default => throw new InvalidArgumentException("{$account->platform->value} has no Meta messaging."),
        };
    }
}
