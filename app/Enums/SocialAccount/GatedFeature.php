<?php

declare(strict_types=1);

namespace App\Enums\SocialAccount;

use App\Enums\PostPlatform\ContentType;
use App\Support\ThreadReplies;

/**
 * A feature a network may only allow once the account grants a scope beyond
 * its publish scopes. Which scope, per network, is that network's
 * `permissions` setting in `config/trypost.php`; an account connected before
 * TryPost asked for it reconnects before a post using the feature can be
 * scheduled or published (`App\Support\ChannelPermissions`).
 */
enum GatedFeature: string
{
    case ThreadReplies = 'thread_replies';

    /**
     * Whether a post uses the feature, mirrored by `featureIsUsedBy()` in
     * `lib/channelPermissions.ts`.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function isUsedBy(?ContentType $type, ?array $meta): bool
    {
        return match ($this) {
            self::ThreadReplies => filled(ThreadReplies::of($meta)),
        };
    }
}
