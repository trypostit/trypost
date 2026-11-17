<?php

declare(strict_types=1);

namespace App\Services\Social\Mastodon;

use GuzzleHttp\Psr7\Query;

/**
 * Builds Mastodon REST API URLs on an account's instance, so the `/api/v1`
 * prefix lives in one place.
 */
class MastodonApi
{
    /**
     * Endpoint for an API path, e.g. `https://mastodon.social/api/v1/statuses`,
     * with its query when it has one. A list repeats its key the way Mastodon
     * reads it (`['id[]' => [1, 2]]` is `id[]=1&id[]=2`), which an array query
     * of the HTTP client cannot send (it writes `id[0]=1`).
     *
     * @param  array<string, mixed>  $query
     */
    public static function url(string $instance, string $path, array $query = []): string
    {
        $url = "{$instance}/api/v1/{$path}";

        if (blank($query)) {
            return $url;
        }

        return "{$url}?".Query::build($query);
    }
}
