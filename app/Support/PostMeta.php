<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Services\Social\Discord\DiscordClient;
use Illuminate\Support\Arr;

/**
 * The meta a post stores for its channel, on every create and update: the
 * format cleaned up (`PostPlatformMetaRules::normalize`), the system keys the
 * post already had (Telegram `reactions`), then what the meta names from the
 * channel's own data filled in, so the web, the API and MCP store the same
 * thing from an id alone. One arm per network that needs it.
 *
 * A lookup that does not find the id leaves the derived value out: saving
 * never fails over a display name.
 */
class PostMeta
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $stored  the meta the post had before this save
     * @return array<string, mixed>
     */
    public static function forChannel(SocialAccount $account, array $meta, array $stored = [], ContentType|string|null $contentType = null): array
    {
        $meta = [
            ...PostPlatformMetaRules::normalize($meta),
            ...Arr::only($stored, PostPlatformMetaRules::SYSTEM_KEYS),
        ];

        if ($contentType instanceof ContentType ? $contentType !== ContentType::XPoll : $contentType !== ContentType::XPoll->value) {
            $meta = Arr::except($meta, 'poll');
        }

        return match ($account->platform) {
            Platform::Discord => self::withDiscordChannelName($account, $meta),
            default => $meta,
        };
    }

    /**
     * The Discord channel name the preview shows. It is never taken from the
     * request: only `channel_id` is, and the name is read from the server's
     * channel list cached by the composer, the API, MCP or a publish. Saving
     * never asks Discord, since it runs inside the post's transaction and
     * queue lock, so without a cached list the post has no name.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function withDiscordChannelName(SocialAccount $account, array $meta): array
    {
        $meta = Arr::except($meta, 'channel_name');
        $channelId = data_get($meta, 'channel_id');

        if (blank($channelId)) {
            return $meta;
        }

        $name = data_get(collect(app(DiscordClient::class)->cachedChannels((string) $account->platform_user_id) ?? [])
            ->firstWhere('id', (string) $channelId), 'name');

        return filled($name) ? [...$meta, 'channel_name' => $name] : $meta;
    }
}
