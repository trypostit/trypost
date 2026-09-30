<?php

declare(strict_types=1);

namespace App\Services\Social\Vk;

/**
 * Builds VK API method URLs and base parameters from config, so the host and
 * version live in one place. VK reports failures as HTTP 200 with an `error`
 * object in the body — callers must check for it (see VkPublisher / VkController).
 */
class VkApi
{
    /**
     * Endpoint for an API method, e.g. `https://api.vk.com/method/wall.post`.
     */
    public static function endpoint(string $method): string
    {
        $base = rtrim((string) config('trypost.platforms.vk.api'), '/');

        return "{$base}/{$method}";
    }

    /**
     * Parameters every request must carry: the access token and API version.
     *
     * @return array{access_token: string, v: string}
     */
    public static function baseParams(string $accessToken): array
    {
        return [
            'access_token' => $accessToken,
            'v' => (string) config('trypost.platforms.vk.api_version'),
        ];
    }
}
