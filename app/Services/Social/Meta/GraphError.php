<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

/**
 * Interprets Meta Graph API (Facebook / Instagram / Threads) error responses.
 *
 * Meta returns rate-limit and transient failures as an ordinary HTTP 4xx with
 * type "OAuthException" (e.g. code 4 / 17 "too many calls", code 1 / 2
 * "temporary problem"), so neither the HTTP status nor the error type can tell
 * a dead token from a throttle. Only error code 190 means the access token
 * itself is invalid or expired — the same signal the platform publish
 * exceptions already use to decide a disconnect.
 */
class GraphError
{
    /**
     * Codes Meta uses for rate-limit and other transient upstream problems.
     * These must never disconnect a still-valid token.
     */
    private const TRANSIENT_CODES = [1, 2, 4, 17];

    /**
     * Whether the given Meta Graph error body means the access token is
     * genuinely invalid or expired (code 190), as opposed to a rate-limit or
     * transient error that must not disconnect a still-valid token.
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function indicatesInvalidToken(?array $body): bool
    {
        return data_get($body, 'error.code') === 190;
    }

    /**
     * Whether the given Meta Graph error body is a known rate-limit or
     * transient upstream problem, as opposed to a confirmed rejection
     * (dead token, bad request, permission denied, etc.).
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function isTransient(?array $body): bool
    {
        return in_array(data_get($body, 'error.code'), self::TRANSIENT_CODES, true);
    }
}
