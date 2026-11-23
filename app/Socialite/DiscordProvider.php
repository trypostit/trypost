<?php

declare(strict_types=1);

namespace App\Socialite;

use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\ProviderInterface;
use Laravel\Socialite\Two\User;
use RuntimeException;

/**
 * Discord bot-authorization OAuth. The user authorizes adding our shared bot to
 * one of their servers; the access-token response carries the `guild` the bot
 * was added to, which becomes the connected account (id = guild id). Channel
 * listing, posting and mentions all use the global bot token, not this token.
 */
class DiscordProvider extends AbstractProvider implements ProviderInterface
{
    protected $scopes = ['bot', 'identify', 'guilds'];

    protected $scopeSeparator = ' ';

    /**
     * The token-exchange response, stashed so the guild it carries can become
     * the Socialite user without an extra API call.
     *
     * @var array<string, mixed>
     */
    private array $tokenResponse = [];

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(config('trypost.platforms.discord.oauth_api').'/authorize', $state);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCodeFields($state = null): array
    {
        return array_merge(parent::getCodeFields($state), [
            'permissions' => (string) config('trypost.platforms.discord.bot_permissions'),
            'integration_type' => 0,
        ]);
    }

    protected function getTokenUrl(): string
    {
        return config('trypost.platforms.discord.oauth_api').'/token';
    }

    /**
     * @return array<string, mixed>
     */
    public function getAccessTokenResponse($code): array
    {
        return $this->tokenResponse = parent::getAccessTokenResponse($code);
    }

    /**
     * The bot-authorization token response includes the guild the bot joined.
     *
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        return (array) data_get($this->tokenResponse, 'guild', []);
    }

    /**
     * @param  array<string, mixed>  $user  The guild object.
     */
    protected function mapUserToObject(array $user): User
    {
        $guildId = data_get($user, 'id');

        // No guild means the user authorized without adding the bot to a server
        // (or cancelled). Fail loudly so the callback shows an error instead of
        // persisting a broken account with a null guild id.
        if (blank($guildId)) {
            throw new RuntimeException('Discord authorization did not include a server.');
        }

        $icon = data_get($user, 'icon');

        return (new User)->setRaw($user)->map([
            'id' => $guildId,
            'nickname' => data_get($user, 'name'),
            'name' => data_get($user, 'name'),
            'avatar' => $guildId && $icon ? "https://cdn.discordapp.com/icons/{$guildId}/{$icon}.png" : null,
        ]);
    }
}
