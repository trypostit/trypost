<?php

declare(strict_types=1);

namespace App\Services\Social\Meta;

use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

/**
 * Interprets Meta Graph API (Facebook / Instagram / Threads) error responses
 * for the connection-health path (ConnectionVerifier's verify/refresh calls
 * against `/me` and `/refresh_access_token`). Does not cover the much larger,
 * platform-specific content-publishing error maps in
 * FacebookPublishException/InstagramPublishException/ThreadsPublishException.
 *
 * Meta returns rate-limit and transient failures as an ordinary HTTP 4xx with
 * type "OAuthException", so neither the HTTP status nor the error type alone
 * can tell a dead token from a throttle. Two independent rate-limit systems
 * exist and both must be treated as transient:
 *
 * - Platform Rate Limits (app/user access tokens — Instagram and Threads
 *   accounts in this app): code 4 "app rate limit", code 17 "user rate
 *   limit". https://developers.facebook.com/docs/graph-api/guides/error-handling/
 * - Business Use Case (BUC) Rate Limits (Page/system-user tokens — Facebook
 *   and InstagramFacebook accounts here use Page tokens): code 80001 "Pages
 *   API", code 80002 "Instagram Platform". Unlike Platform Rate Limits, BUC
 *   rejections come back as an ordinary HTTP 400, not 429. BUC also covers
 *   several other Meta products (Marketing API, WhatsApp, Messenger, ...)
 *   with their own 80000-series codes — irrelevant here since this app never
 *   calls those APIs; add a code only once we actually call the surface it
 *   belongs to, verified against the table below, not guessed.
 *   https://developers.facebook.com/docs/graph-api/overview/rate-limiting/
 * - Generic transient upstream problems: code 1, code 2.
 *
 * Only a known transient code (or a body Meta didn't return as parseable
 * JSON at all — a WAF block page, a truncated response, a gateway hiccup —
 * which carries no confirmed rejection either) means the failure isn't a
 * confirmed rejection. Every other 4xx — including error codes other than
 * 190, which Meta also uses to signal a dead token (e.g. code 100 seen on a
 * genuinely revoked Threads token) — means the account needs to be
 * reconnected.
 */
class GraphError
{
    /**
     * Codes Meta uses for rate-limit and other transient upstream problems.
     * These must never disconnect a still-valid token.
     */
    private const TRANSIENT_CODES = [1, 2, 4, 17, 80001, 80002];

    /**
     * Whether the given Meta Graph error body is a known rate-limit or
     * transient upstream problem, as opposed to a confirmed rejection
     * (dead token, bad request, permission denied, etc.). A body that
     * doesn't parse as JSON is treated as transient too — we have no
     * confirmed rejection from Meta to act on.
     *
     * @param  array<string, mixed>|null  $body
     */
    public static function isTransient(?array $body): bool
    {
        return $body === null || in_array(data_get($body, 'error.code'), self::TRANSIENT_CODES, true);
    }

    /**
     * Whether a failed Meta Graph API response — status and body together —
     * represents a transient problem that must not disconnect a still-valid
     * token, as opposed to a confirmed rejection.
     */
    public static function isTransientFailure(Response $response): bool
    {
        return $response->serverError()
            || $response->status() === 429
            || self::isTransient($response->json());
    }

    /**
     * Classify a failed Meta Graph "/me" verify call into the exception the
     * caller should throw: a rate-limit or other transient upstream problem
     * must not disconnect a still-valid token, but every other rejection —
     * including error codes other than 190, which Meta also uses to signal a
     * dead token — means the account genuinely needs to be reconnected.
     */
    public static function classifyVerifyFailure(Response $response, string $label): PlatformUnavailableException|TokenExpiredException
    {
        if (self::isTransientFailure($response)) {
            return new PlatformUnavailableException(
                "{$label} API returned {$response->status()} during verification",
                $response->status(),
            );
        }

        return new TokenExpiredException("{$label} access token is invalid or expired");
    }
}
