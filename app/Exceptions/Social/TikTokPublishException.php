<?php

declare(strict_types=1);

namespace App\Exceptions\Social;

use App\Exceptions\TokenExpiredException;
use Illuminate\Http\Client\Response;

class TikTokPublishException extends SocialPublishException
{
    public static function fromApiResponse(mixed $response): static
    {
        /** @var Response $response */
        $body = $response->json();
        $rawResponse = $response->body();

        $errorCode = data_get($body, 'error.code');
        $errorMessage = data_get($body, 'error.message', 'An unknown TikTok error occurred.');

        if (self::isConfirmedDeadToken($response)) {
            throw new TokenExpiredException(
                message: $errorMessage,
                platformErrorCode: $errorCode,
            );
        }

        [$message, $category] = match ($errorCode) {
            'scope_not_authorized' => ['Missing required permissions. Please reconnect with all scopes.', ErrorCategory::Permission],
            'scope_permission_missed' => ['Additional permissions required. Please reconnect.', ErrorCategory::Permission],
            'rate_limit_exceeded' => ['TikTok rate limit exceeded. Please try again later.', ErrorCategory::RateLimit],
            'invalid_file_upload' => ['File does not meet API specifications.', ErrorCategory::MediaFormat],
            'invalid_params' => ['Invalid request parameters.', ErrorCategory::MediaFormat],
            'internal_error' => ['TikTok server error. Please try again later.', ErrorCategory::ServerError],
            'reached_active_user_cap' => ['Daily active user quota reached.', ErrorCategory::RateLimit],
            'unaudited_client_can_only_post_to_private_accounts' => ['App not approved for public posting.', ErrorCategory::Permission],
            'url_ownership_unverified' => ['Domain ownership not verified.', ErrorCategory::Permission],
            'privacy_level_option_mismatch' => ['Privacy level not available for this account.', ErrorCategory::Permission],
            'app_version_check_failed' => ['TikTok app update required.', ErrorCategory::Permission],
            default => [$errorMessage, ErrorCategory::Unknown],
        };

        return new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $errorCode !== null ? (string) $errorCode : null,
            rawResponse: $rawResponse,
        );
    }

    public static function fromFailReason(string $failReason, ?string $rawResponse = null): static
    {
        [$message, $category] = match ($failReason) {
            'file_format_check_failed' => ['Unsupported media format.', ErrorCategory::MediaFormat],
            'duration_check_failed' => ['Video duration is not within allowed limits.', ErrorCategory::MediaFormat],
            'frame_rate_check_failed' => ['Video frame rate is not supported.', ErrorCategory::MediaFormat],
            'picture_size_check_failed' => ['Image dimensions exceed limits.', ErrorCategory::MediaFormat],
            'video_pull_failed' => ['Failed to download video from URL.', ErrorCategory::ServerError],
            'photo_pull_failed' => ['Failed to download photo from URL.', ErrorCategory::ServerError],
            'publish_cancelled' => ['Publishing was cancelled.', ErrorCategory::ContentPolicy],
            'auth_removed' => ['App access was revoked during processing.', ErrorCategory::Permission],
            'spam_risk_too_many_posts' => ['Daily posting limit reached. Try again tomorrow.', ErrorCategory::RateLimit],
            'spam_risk_user_banned_from_posting' => ['Account is banned from posting.', ErrorCategory::ContentPolicy],
            'spam_risk_text' => ['TikTok detected spam in the description.', ErrorCategory::ContentPolicy],
            'spam_risk' => ['Publishing request flagged as high-risk.', ErrorCategory::ContentPolicy],
            'internal' => ['TikTok server error. Please try again.', ErrorCategory::ServerError],
            default => [$failReason, ErrorCategory::Unknown],
        };

        return new static(
            userMessage: $message,
            category: $category,
            platformErrorCode: $failReason,
            rawResponse: $rawResponse,
        );
    }

    public function platform(): string
    {
        return 'tiktok';
    }

    /**
     * Whether this response confirms the account's own access_token is dead
     * (not merely a transient or content-specific failure). Shared with
     * ConnectionVerifier so both the publish and verify paths agree on what
     * a dead TikTok token looks like. 10001/10002 are the numeric forms of
     * the same access_token_invalid/expired conditions TikTok also reports.
     *
     * A bare HTTP 401 is deliberately NOT treated as confirmed-dead here:
     * TikTok also returns 401 for scope_not_authorized/scope_permission_missed
     * (missing video.publish grant — see
     * https://developers.tiktok.com/doc/content-posting-api-reference-direct-post),
     * which is a scope gap, not a dead token, and must stay a Permission-category
     * publish failure (see the match arms above) rather than disconnecting the
     * account. ConnectionVerifier adds its own bare-401 check on top of this
     * one, because /v2/user/info/ only needs the always-granted user.info.basic
     * scope — a 401 there can't be a scope gap, so it's an unambiguous signal
     * the token itself is dead.
     */
    public static function isConfirmedDeadToken(Response $response): bool
    {
        $errorCode = data_get($response->json(), 'error.code');

        return in_array($errorCode, ['access_token_invalid', 'access_token_expired', 10001, 10002]);
    }
}
