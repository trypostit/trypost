<?php

declare(strict_types=1);

namespace App\Services\Social\Mastodon;

/**
 * Builds Mastodon REST API URLs on an account's instance, so the `/api/v1`
 * prefix lives in one place.
 */
class MastodonApi
{
    /**
     * Endpoint for an API path, e.g. `https://mastodon.social/api/v1/statuses`.
     */
    public static function url(string $instance, string $path): string
    {
        return "{$instance}/api/v1/{$path}";
    }
}
