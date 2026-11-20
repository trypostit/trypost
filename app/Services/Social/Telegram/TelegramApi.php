<?php

declare(strict_types=1);

namespace App\Services\Social\Telegram;

/**
 * Builds Telegram Bot API URLs from the configured token + host, so the
 * `bot<token>/<method>` shape and config keys live in one place.
 */
class TelegramApi
{
    public static function token(): string
    {
        return (string) config('trypost.platforms.telegram.bot_token');
    }

    /**
     * Endpoint for a Bot API method, e.g. `https://api.telegram.org/bot<token>/sendMessage`.
     */
    public static function endpoint(string $method): string
    {
        $base = self::baseUrl();
        $token = self::token();

        return "{$base}/bot{$token}/{$method}";
    }

    /**
     * Download URL for a file path returned by `getFile`.
     */
    public static function fileUrl(string $path): string
    {
        $base = self::baseUrl();
        $token = self::token();

        return "{$base}/file/bot{$token}/{$path}";
    }

    /**
     * The bot's `t.me` link, or null while no bot username is configured.
     */
    public static function botUrl(): ?string
    {
        $username = config('trypost.platforms.telegram.bot_username');

        return filled($username) ? config('trypost.platforms.telegram.deep_link')."/{$username}" : null;
    }

    private static function baseUrl(): string
    {
        return config('trypost.platforms.telegram.api');
    }
}
