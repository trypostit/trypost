<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Google Business Profile identifies a location by its full
 * `accounts/{account}/locations/{location}` resource name. The Business Profile
 * web UI takes the trailing location id as an un-obfuscated deep link (`/l/u{id}`).
 * Both the publisher (post URL fallback) and the social account model (profile
 * URL) need that same link, so the conversion lives here once.
 *
 * @see https://developers.google.com/my-business/content/locations-setup
 */
class GoogleBusinessResourceName
{
    /**
     * The Business Profile dashboard URL for a location resource name.
     * API location ids are un-obfuscated, so the deep link must use the `u` prefix.
     */
    public static function dashboardUrl(string $resourceName): string
    {
        $locationId = Str::afterLast($resourceName, '/');

        return rtrim((string) config('trypost.platforms.google_business.dashboard'), '/')."/dashboard/l/u{$locationId}";
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
