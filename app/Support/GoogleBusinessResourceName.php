<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Google Business Profile location resource names.
 *
 * v1 Business Information returns `locations/{id}`. v4 Local Posts wants
 * `accounts/{account}/locations/{id}`. The dashboard deep-link uses the
 * trailing id with an un-obfuscated `u` prefix.
 *
 * @see https://developers.google.com/my-business/content/locations-setup
 */
class GoogleBusinessResourceName
{
    public static function dashboardUrl(string $resourceName): string
    {
        return (string) config('trypost.platforms.google_business.dashboard').'/dashboard/l/u'.self::locationId($resourceName);
    }

    public static function toFullLocationName(string $accountName, string $shortLocationName): string
    {
        return "{$accountName}/locations/".self::locationId($shortLocationName);
    }

    /**
     * Publish needs the v4 `location_id`; verify and analytics need the v1
     * `location_name`. OAuth writes both — a row missing either is unusable.
     *
     * @return array{id: string, name: string}|null
     */
    public static function connectedLocation(mixed $meta): ?array
    {
        $id = (string) data_get($meta, 'location_id');
        $name = (string) data_get($meta, 'location_name');

        if (blank($id) || blank($name)) {
            return null;
        }

        return ['id' => $id, 'name' => $name];
    }

    private static function locationId(string $resourceName): string
    {
        return Str::afterLast($resourceName, '/');
    }
}
