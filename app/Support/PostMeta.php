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

        if (ContentType::resolve($contentType) !== ContentType::XPoll) {
            $meta = Arr::except($meta, 'poll');
        }

        return match ($account->platform) {
            Platform::Discord => self::withDiscordChannelName($account, $meta),
            default => $meta,
        };
    }

    /**
     * The meta an update starts from: the stored meta with the sent keys on top.
     * A sent poll is merged into the stored one key by key, so sending only its
     * choices keeps its length (and the reverse); `options` is replaced as a
     * whole list.
     *
     * @param  array<string, mixed>  $stored
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    public static function merge(array $stored, array $incoming): array
    {
        $merged = [...$stored, ...$incoming];
        $storedPoll = data_get($stored, 'poll');
        $incomingPoll = data_get($incoming, 'poll');

        if (is_array($storedPoll) && is_array($incomingPoll)) {
            $merged['poll'] = [...$storedPoll, ...$incomingPoll];
        }

        return $merged;
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
