<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Google Business Profile identifies a location by its full
 * `accounts/{account}/locations/{location}` resource name, while the Business
 * Profile dashboard links by the bare trailing location id. Both the publisher
 * (post URLs) and the social account model (profile URL) need that same link,
 * so the conversion lives here once.
 */
class GoogleBusinessResourceName
{
    /**
     * The Business Profile dashboard URL for a location resource name, matching
     * postiz's synthesized URL shape.
     */
    public static function dashboardUrl(string $resourceName): string
    {
        $segments = explode('/', $resourceName);

        return 'https://business.google.com/locations/'.end($segments);
    }

    /**
     * The full `accounts/{account}/locations/{location}` name the v4 Local Posts
     * API needs, built from an account name and the short `locations/{location}`
     * name the v1 Business Information API returns.
     */
    public static function toFullLocationName(string $accountName, string $shortLocationName): string
    {
        $locationId = str_starts_with($shortLocationName, 'locations/')
            ? substr($shortLocationName, strlen('locations/'))
            : $shortLocationName;

        return "{$accountName}/locations/{$locationId}";
    }
}
