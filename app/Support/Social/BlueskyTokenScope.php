<?php

declare(strict_types=1);

namespace App\Support\Social;

/**
 * Reads the documented `scope` claim of a Bluesky session's access token:
 * `com.atproto.access` (account password), `com.atproto.appPassPrivileged`
 * (app password with DM access) or `com.atproto.appPass` (no DM access).
 *
 * @see https://github.com/bluesky-social/atproto/blob/main/packages/pds/src/auth-scope.ts
 */
class BlueskyTokenScope
{
    private const array DIRECT_MESSAGE_SCOPES = ['com.atproto.access', 'com.atproto.appPassPrivileged'];

    public static function allowsDirectMessages(?string $accessJwt): bool
    {
        return in_array(self::of($accessJwt), self::DIRECT_MESSAGE_SCOPES, true);
    }

    public static function of(?string $accessJwt): ?string
    {
        $parts = explode('.', (string) $accessJwt);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $scope = is_array($payload) ? data_get($payload, 'scope') : null;

        return is_string($scope) ? $scope : null;
    }
}
