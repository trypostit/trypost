<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

final class ConfigEnvironment
{
    public static function baseUrl(string $key, string $default): string
    {
        return Str::rtrim((string) env($key, $default), '/');
    }

    /**
     * OAuth scopes from a comma- or space-separated env value.
     *
     * @return list<string>
     */
    public static function oauthScopes(string $key, string $default): array
    {
        $configured = (string) env($key);
        $scopes = filled($configured) ? $configured : $default;

        return OAuthScopes::parse($scopes);
    }
}
