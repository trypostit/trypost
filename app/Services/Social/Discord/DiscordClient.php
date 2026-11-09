<?php

declare(strict_types=1);

namespace App\Services\Social\Discord;

use App\Exceptions\PlatformUnavailableException;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

/**
 * Read-side Discord API calls made with the global bot token (channel listing,
 * mention search, guild verification). Posting lives in DiscordPublisher.
 */
class DiscordClient
{
    use HasSocialHttpClient;

    /**
     * Channel types that accept a direct message via POST /channels/{id}/messages:
     * 0 = text, 5 = announcement. Forum (15) is excluded — it only accepts a thread
     * (forum post), so a plain message returns "Cannot send messages in a non-text channel".
     */
    private const POSTABLE_CHANNEL_TYPES = [0, 5];

    private const CHANNELS_TTL = 300;

    private const PERMISSION_ADMINISTRATOR = 0x8;

    private const PERMISSION_VIEW_CHANNEL = 0x400;

    private const PERMISSION_SEND_MESSAGES = 0x800;

    public function baseUrl(): string
    {
        return config('trypost.platforms.discord.api');
    }

    /**
     * The guild's channels when a recent `channels()` call left them in the
     * cache, without asking Discord: null when they are not cached.
     *
     * @return list<array{id: string, name: string}>|null
     */
    public function cachedChannels(string $guildId): ?array
    {
        $channels = Cache::get($this->channelsCacheKey($guildId));

        return is_array($channels) ? $channels : null;
    }

    /**
     * The channels of a guild the bot can actually post into: postable type
     * (text/announcement) AND the bot has VIEW_CHANNEL + SEND_MESSAGES there.
     *
     * @return list<array{id: string, name: string}>
     *
     * @throws PlatformUnavailableException when the lookup fails transiently, so
     *                                      callers (e.g. the publish-time channel guard) retry instead of
     *                                      treating an empty result as "channel not found".
     */
    public function channels(string $guildId): array
    {
        // Cached per guild for 5 minutes — channels change rarely and this runs
        // both in the composer picker and on every publish (the channel guard),
        // all against the shared, rate-limited bot token. A transient failure
        // throws and is NOT cached, so the next call retries.
        return Cache::remember($this->channelsCacheKey($guildId), self::CHANNELS_TTL, function () use ($guildId) {
            $response = $this->bot()->get("{$this->baseUrl()}/guilds/{$guildId}/channels");

            if ($response->failed()) {
                throw new PlatformUnavailableException("Discord channel lookup failed ({$response->status()}).", $response->status());
            }

            $channels = $response->json();

            if (! is_array($channels)) {
                return [];
            }

            $rolePermissions = $this->guildRolePermissions($guildId);
            $botRoleIds = $this->botRoleIds($guildId);

            return collect($channels)
                ->filter(fn ($channel) => in_array((int) data_get($channel, 'type'), self::POSTABLE_CHANNEL_TYPES, true))
                ->filter(fn ($channel) => $this->botCanPostInChannel($channel, $guildId, $rolePermissions, $botRoleIds))
                ->map(fn ($channel) => [
                    'id' => (string) data_get($channel, 'id'),
                    'name' => (string) data_get($channel, 'name'),
                ])
                ->values()
                ->all();
        });
    }

    private function channelsCacheKey(string $guildId): string
    {
        return "discord:channels:{$guildId}";
    }

    /**
     * Whether the bot has VIEW_CHANNEL + SEND_MESSAGES in a channel, computed from
     * its roles' base permissions and the channel's permission overwrites (Discord's
     * standard algorithm). Falls back to `true` when the bot's roles can't be
     * resolved, so a transient API hiccup never hides every channel from the picker.
     *
     * @param  array<string, mixed>  $channel
     * @param  array<string, int>  $rolePermissions  role id => base permission bitfield
     * @param  list<string>|null  $botRoleIds  role ids assigned to the bot, or null when unknown
     */
    private function botCanPostInChannel(array $channel, string $guildId, array $rolePermissions, ?array $botRoleIds): bool
    {
        if ($botRoleIds === null || $rolePermissions === []) {
            return true;
        }

        $base = $rolePermissions[$guildId] ?? 0; // @everyone role id == guild id
        foreach ($botRoleIds as $roleId) {
            $base |= ($rolePermissions[$roleId] ?? 0);
        }

        if (($base & self::PERMISSION_ADMINISTRATOR) !== 0) {
            return true;
        }

        $permissions = $this->applyChannelOverwrites($base, $channel, $guildId, $botRoleIds);

        return ($permissions & self::PERMISSION_VIEW_CHANNEL) !== 0
            && ($permissions & self::PERMISSION_SEND_MESSAGES) !== 0;
    }

    /**
     * Applies a channel's permission_overwrites to a base bitfield in Discord's
     * documented order: @everyone overwrite, then the union of role overwrites,
     * then the bot's member overwrite.
     *
     * @param  array<string, mixed>  $channel
     * @param  list<string>  $botRoleIds
     */
    private function applyChannelOverwrites(int $permissions, array $channel, string $guildId, array $botRoleIds): int
    {
        $overwrites = collect((array) data_get($channel, 'permission_overwrites', []));

        $everyone = $overwrites->first(fn ($overwrite) => (string) data_get($overwrite, 'id') === $guildId);
        if ($everyone !== null) {
            $permissions = ($permissions & ~(int) data_get($everyone, 'deny', 0)) | (int) data_get($everyone, 'allow', 0);
        }

        $roleAllow = 0;
        $roleDeny = 0;
        foreach ($overwrites as $overwrite) {
            if ((int) data_get($overwrite, 'type') === 0 && in_array((string) data_get($overwrite, 'id'), $botRoleIds, true)) {
                $roleAllow |= (int) data_get($overwrite, 'allow', 0);
                $roleDeny |= (int) data_get($overwrite, 'deny', 0);
            }
        }
        $permissions = ($permissions & ~$roleDeny) | $roleAllow;

        $botId = (string) config('services.discord.client_id');
        $member = $overwrites->first(fn ($overwrite) => (int) data_get($overwrite, 'type') === 1 && (string) data_get($overwrite, 'id') === $botId);
        if ($member !== null) {
            $permissions = ($permissions & ~(int) data_get($member, 'deny', 0)) | (int) data_get($member, 'allow', 0);
        }

        return $permissions;
    }

    /**
     * Base permission bitfield per guild role (role id => permissions). Returns []
     * when the roles can't be fetched, which disables permission filtering.
     *
     * @return array<string, int>
     */
    private function guildRolePermissions(string $guildId): array
    {
        $permissions = [];

        foreach ($this->getList("{$this->baseUrl()}/guilds/{$guildId}/roles") as $role) {
            $permissions[(string) data_get($role, 'id')] = (int) data_get($role, 'permissions', 0);
        }

        return $permissions;
    }

    /**
     * The role ids assigned to the bot in a guild, or null when they can't be
     * resolved (no client id configured, or the member lookup failed).
     *
     * @return list<string>|null
     */
    private function botRoleIds(string $guildId): ?array
    {
        $botId = (string) config('services.discord.client_id');

        if ($botId === '') {
            return null;
        }

        $response = $this->bot()->get("{$this->baseUrl()}/guilds/{$guildId}/members/{$botId}");

        if ($response->failed()) {
            return null;
        }

        $roles = $response->json('roles');

        return is_array($roles) ? array_map('strval', $roles) : null;
    }

    /**
     * Mentionable targets matching a query: @everyone/@here, roles, then members.
     *
     * @return list<array{id: string, label: string, type: string}>
     */
    public function mentions(string $guildId, string $query): array
    {
        $query = trim($query);
        $needle = mb_strtolower($query);

        $specials = collect([
            ['id' => 'everyone', 'label' => '@everyone', 'type' => 'everyone'],
            ['id' => 'here', 'label' => '@here', 'type' => 'here'],
        ])->filter(fn ($item) => $needle === '' || str_contains($item['label'], $needle));

        $roles = collect($this->getList("{$this->baseUrl()}/guilds/{$guildId}/roles"))
            ->filter(fn ($role) => $needle === '' || str_contains(mb_strtolower((string) data_get($role, 'name')), $needle))
            ->take(10)
            ->map(fn ($role) => [
                'id' => (string) data_get($role, 'id'),
                'label' => '@'.data_get($role, 'name'),
                'type' => 'role',
            ]);

        $members = $query === '' ? collect() : collect(
            $this->getList("{$this->baseUrl()}/guilds/{$guildId}/members/search", ['query' => $query, 'limit' => 10])
        )->map(fn ($member) => [
            'id' => (string) data_get($member, 'user.id'),
            'label' => '@'.(data_get($member, 'user.global_name') ?: data_get($member, 'user.username')),
            'type' => 'user',
        ]);

        return $specials->concat($roles)->concat($members)->values()->all();
    }

    /**
     * GETs a Discord list endpoint, returning [] on failure or a non-list body
     * (a failed call returns an error OBJECT, which must not be iterated as rows).
     *
     * @param  array<string, mixed>  $query
     * @return array<int, mixed>
     */
    private function getList(string $url, array $query = []): array
    {
        $body = $this->bot()->get($url, $query)->json();

        return is_array($body) && array_is_list($body) ? $body : [];
    }

    public function getGuild(string $guildId): Response
    {
        return $this->bot()->get("{$this->baseUrl()}/guilds/{$guildId}", ['with_counts' => 'true']);
    }

    public function getMessage(string $channelId, string $messageId): Response
    {
        return $this->bot()->get("{$this->baseUrl()}/channels/{$channelId}/messages/{$messageId}");
    }

    private function bot(): PendingRequest
    {
        return $this->socialHttp()
            ->withToken((string) config('trypost.platforms.discord.bot_token'), 'Bot');
    }
}
