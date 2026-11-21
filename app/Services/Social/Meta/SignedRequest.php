<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

/**
 * Reads the `signed_request` Meta posts to an app's Deauthorize and Data
 * Deletion callbacks: `<signature>.<payload>`, both base64url, where the
 * signature is the HMAC-SHA256 of the encoded payload keyed with the app secret.
 *
 * @see https://developers.facebook.com/docs/development/create-an-app/app-dashboard/data-deletion-callback
 * @see https://developers.facebook.com/docs/facebook-login/guides/advanced/manual-flow#deauth-callback
 */
final class SignedRequest
{
    private const string ALGORITHM = 'HMAC-SHA256';

    /**
     * The payload's user id when the request is signed with this secret, else
     * null: a wrong signature, another algorithm, a malformed value or a
     * payload without a user all read as "not from Meta".
     */
    public static function userId(string $signedRequest, string $secret): ?string
    {
        if ($secret === '' || substr_count($signedRequest, '.') !== 1) {
            return null;
        }

        [$encodedSignature, $encodedPayload] = explode('.', $signedRequest);

        $signature = self::decode($encodedSignature);
        $payload = json_decode((string) self::decode($encodedPayload), true);

        if ($signature === null || ! is_array($payload) || data_get($payload, 'algorithm') !== self::ALGORITHM) {
            return null;
        }

        if (! hash_equals(hash_hmac('sha256', $encodedPayload, $secret, true), $signature)) {
            return null;
        }

        $userId = data_get($payload, 'user_id');

        return is_scalar($userId) && filled((string) $userId) ? (string) $userId : null;
    }

    private static function decode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false || $decoded === '' ? null : $decoded;
    }
}
